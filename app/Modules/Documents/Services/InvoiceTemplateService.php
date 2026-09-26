<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Contracts\DocumentRenderer;
use App\Modules\Documents\Models\InvoiceTemplate;
use App\Modules\Documents\Support\RenderedDocument;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use NumberFormatter;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Invoice templates, invoice rendering and packing slips (spec §43.1 to
 * §43.3). Invoices are rendered on demand and never stored.
 */
final readonly class InvoiceTemplateService
{
    /** tenant_settings.date_format tokens → PHP date format. */
    private const array DATE_FORMATS = [
        'DD/MM/YYYY' => 'd/m/Y', 'MM/DD/YYYY' => 'm/d/Y', 'YYYY-MM-DD' => 'Y-m-d', 'DD MMM YYYY' => 'd M Y', 'MMM DD, YYYY' => 'M d, Y',
    ];

    /** Thermal paper widths in points (58 mm, 80 mm). */
    private const array THERMAL_WIDTHS = ['58mm' => 164.4, '80mm' => 226.8];

    public function __construct(
        private DocumentRenderer $renderer,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @return Collection<int, InvoiceTemplate>
     */
    public function listTemplates(): Collection
    {
        return InvoiceTemplate::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'invoice_type' => [$required, Rule::in(InvoiceTemplate::TYPES)],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/_-]*$/'],
            'numbering_type' => ['sometimes', Rule::in(['sequential', 'random'])],
            'start_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'header_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'payment_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sales_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'logo_media_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.media', 'id')],
            'logo_height' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:1000'],
            'logo_width' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:1000'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'date_format' => ['sometimes', 'nullable', Rule::in(array_keys(self::DATE_FORMATS))],
            ...array_fill_keys(InvoiceTemplate::FLAGS, ['sometimes', 'boolean']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function createTemplate(array $data): InvoiceTemplate
    {
        return DB::connection('tenant')->transaction(static function () use ($data): InvoiceTemplate {
            $template = new InvoiceTemplate($data);
            $template->forceFill(['is_default' => ! InvoiceTemplate::query()->where('is_default', true)->exists()])->save();

            return $template;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function updateTemplate(InvoiceTemplate $template, array $data): InvoiceTemplate
    {
        $template->fill($data)->save();

        return $template;
    }

    /**
     * Blocked for the default template: another must become the default
     * first, so numbering always has a source.
     */
    public function deleteTemplate(InvoiceTemplate $template): void
    {
        if ($template->is_default) {
            throw ApiException::unprocessable('default_template', 'Make another template the default before deleting this one.');
        }

        $template->delete();
    }

    /**
     * Moving the default carries the sequential counter over, so numbers
     * never repeat when the new default has a lower start.
     */
    public function setDefault(InvoiceTemplate $template): InvoiceTemplate
    {
        DB::connection('tenant')->transaction(static function () use ($template): void {
            $current = InvoiceTemplate::query()->where('is_default', true)->lockForUpdate()->first();

            if ($current === null || $current->id !== $template->id) {
                $current?->forceFill(['is_default' => false])->save();
                $template->forceFill([
                    'is_default' => true,
                    'last_number' => max((int) $template->last_number, (int) $current?->last_number) ?: null,
                ])->save();
            }
        });

        return $template->refresh();
    }

    /**
     * Renders the order's invoice with the given template or the default:
     * a PDF for A4, printable HTML for thermal sizes (§43.2).
     */
    public function renderInvoice(Order $order, ?InvoiceTemplate $template = null): RenderedDocument
    {
        $template ??= InvoiceTemplate::query()->where('is_default', true)->first() ?? InvoiceTemplateDefaults::create();
        $order->loadMissing(['items.product:id,hsn_code,description', 'items.warehouse:id,name']);
        $settings = $this->settingKeys();
        $currency = $order->currency_code;
        $gst = $settings['invoice_format'] === 'gst' && $settings['india_gst_enabled'];
        $zatca = (bool) $settings['saudi_zatca_enabled'];

        if ($zatca && trim((string) $settings['vat_registration_number']) === '') {
            throw ApiException::unprocessable('vat_registration_required', 'Set the VAT registration number before issuing ZATCA invoices.');
        }

        $payments = OrderPayment::query()->where('order_id', $order->id)->where('status', OrderPayment::SUCCESSFUL)->orderBy('id')->get();
        $netPaid = $payments->reduce(static fn (string $sum, OrderPayment $p): string => Money::add($sum, (string) $p->amount_paid), Money::normalize(0));
        $money = fn (string|int|float|null $amount): string => $this->formatMoney((string) ($amount ?? '0'), $currency, $settings);
        $issuedAt = $order->confirmed_at ?? $order->placed_at ?? $order->created_at ?? now();
        $number = $order->invoice_number ?? $order->order_number;

        $data = [
            'template' => $template,
            'order' => $order,
            'is_test' => $order->is_test,
            'thermal' => $template->isThermal(),
            'color' => $template->primary_color ?? '#111827',
            'number' => $number,
            'date' => $this->formatDate($issuedAt, $template->date_format ?? $settings['date_format']),
            'store' => [
                'name' => (string) $settings['store_name'],
                'email' => $settings['store_contact_email'],
                'phone' => $settings['store_contact_phone'],
                'address' => $this->addressLines(is_array($settings['store_address']) ? $settings['store_address'] : null),
                'registration' => $settings['vat_registration_number'],
                'logo' => $this->logo($template->logo_media_id ?? (is_numeric($settings['store_logo_media_id']) ? (int) $settings['store_logo_media_id'] : null)),
            ],
            'bill_to' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'address' => $this->addressLines($order->billing_address),
            ],
            'lines' => $order->items->map(fn (OrderItem $item): array => [
                'name' => $item->name_snapshot,
                'sku' => $item->sku_snapshot,
                'description' => $item->product?->description === null ? null : mb_strimwidth(strip_tags((string) $item->product->description), 0, 160, '…'),
                'hsn_code' => $item->product?->hsn_code,
                'warehouse' => $item->warehouse?->name,
                'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                'unit_price' => $money($item->unit_price),
                'discount' => Money::isPositive((string) $item->discount_amount) ? $money($item->discount_amount) : null,
                'tax' => $money($item->tax_amount),
                'tax_breakdown' => collect($item->tax_breakdown ?? [])->map($money)->all(),
                'total' => $money($item->line_total),
            ])->all(),
            'gst' => $gst,
            'gst_totals' => $gst ? $this->gstTotals($order->items, $money) : [],
            'totals' => [
                'subtotal' => $money($order->subtotal),
                'discount' => Money::isPositive((string) $order->discount_amount) ? $money($order->discount_amount) : null,
                'shipping' => $money(Money::sub((string) $order->shipping_amount, (string) $order->shipping_discount_amount)),
                'tax' => $money(Money::add((string) $order->tax_amount, (string) $order->shipping_tax_amount)),
                'total' => $money($order->total),
                'paid' => $money($netPaid),
                'due' => $money(Money::max(Money::sub((string) $order->total, $netPaid), '0')),
            ],
            'amount_in_words' => $template->show_amount_in_words ? $this->amountInWords((string) $order->total, $currency) : null,
            'payments' => $payments->where('kind', OrderPayment::PAYMENT)->map(fn (OrderPayment $p): array => [
                'method' => str_replace('_', ' ', (string) ($p->provider ?? $p->payment_method)),
                'amount' => $money($p->amount_paid),
                'date' => $this->formatDate($p->paid_at ?? $p->created_at, $template->date_format ?? $settings['date_format']),
            ])->values()->all(),
            'barcode' => $template->show_barcode ? $this->renderer->barcode($number) : null,
            'qr_code' => match (true) {
                $zatca => $this->renderer->qrCode($this->zatcaPayload((string) $settings['store_name'], (string) $settings['vat_registration_number'], $issuedAt, (string) $order->total, Money::add((string) $order->tax_amount, (string) $order->shipping_tax_amount))),
                $template->show_qr_code => $this->renderer->qrCode($number),
                default => null,
            },
        ];

        if ($template->isThermal()) {
            return new RenderedDocument($this->renderer->html('documents.invoice-thermal', $data + ['width' => self::THERMAL_WIDTHS[$template->invoice_type]]), 'text/html', "invoice-{$number}.html");
        }

        return new RenderedDocument($this->renderer->pdf('documents.invoice', $data), 'application/pdf', "invoice-{$number}.pdf");
    }

    /**
     * A price-free pick list grouped by warehouse (§43.3); 404 while
     * packing_slip_enabled is off.
     */
    public function renderPackingSlip(Order $order): RenderedDocument
    {
        if (! (bool) $this->settings->get('packing_slip_enabled', true)) {
            throw new ApiException('not_found', 'Packing slips are turned off for this store.', 404);
        }

        $order->loadMissing('items.warehouse:id,name');
        $groups = $order->items->groupBy(static fn (OrderItem $item): string => $item->warehouse->name ?? 'Unassigned')
            ->map(static fn (Collection $items): array => $items->map(static fn (OrderItem $item): array => [
                'name' => $item->name_snapshot,
                'sku' => $item->sku_snapshot,
                'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
            ])->all())
            ->all();

        return new RenderedDocument($this->renderer->pdf('documents.packing-slip', [
            'order' => $order,
            'is_test' => $order->is_test,
            'store_name' => (string) $this->settings->get('store_name', ''),
            'shipping_address' => $this->addressLines($order->shipping_address),
            'groups' => $groups,
        ]), 'application/pdf', "packing-slip-{$order->order_number}.pdf");
    }

    /**
     * ZATCA simplified-invoice QR (§43.2): tags 1 to 5 as TLV, base64.
     */
    public function zatcaPayload(string $seller, string $vatNumber, Carbon $issuedAt, string $total, string $vat): string
    {
        $tlv = '';

        foreach ([$seller, $vatNumber, $issuedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z'), Money::normalize($total), Money::normalize($vat)] as $index => $value) {
            $value = mb_strcut($value, 0, 255);
            $tlv .= chr($index + 1).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingKeys(): array
    {
        $keys = ['store_name', 'store_logo_media_id', 'store_contact_email', 'store_contact_phone', 'store_address', 'vat_registration_number', 'date_format',
            'default_currency_position', 'decimal_digits', 'invoice_format', 'india_gst_enabled', 'saudi_zatca_enabled'];

        return array_combine($keys, array_map(fn (string $key): mixed => $this->settings->get($key), $keys));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function formatMoney(string $amount, string $currency, array $settings): string
    {
        $digits = min((int) ($settings['decimal_digits'] ?? 2), Money::decimals($currency) ?: (int) ($settings['decimal_digits'] ?? 2));
        $number = number_format((float) Money::round($amount, $currency), $digits);

        return ($settings['default_currency_position'] ?? 'before') === 'after' ? "{$number} {$currency}" : "{$currency} {$number}";
    }

    private function formatDate(Carbon $date, ?string $format): string
    {
        $timezone = (string) $this->settings->get('timezone', 'UTC');

        return $date->copy()->setTimezone($timezone)->format(self::DATE_FORMATS[$format ?? ''] ?? 'Y-m-d');
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @param  callable(string): string  $money
     * @return array<string, string>
     */
    private function gstTotals(Collection $items, callable $money): array
    {
        $totals = [];

        foreach ($items as $item) {
            foreach ((array) $item->tax_breakdown as $component => $amount) {
                $totals[$component] = Money::add($totals[$component] ?? '0', (string) $amount);
            }
        }

        return array_map($money, $totals);
    }

    private function amountInWords(string $amount, string $currency): ?string
    {
        if (! class_exists(NumberFormatter::class)) {
            return null;
        }

        $formatter = new NumberFormatter((string) $this->settings->get('locale', 'en'), NumberFormatter::SPELLOUT);
        $major = (int) floor((float) $amount);
        $minor = Money::toMinor($amount, $currency) - $major * (10 ** Money::decimals($currency));
        $words = ucfirst((string) $formatter->format($major)).' '.$currency;

        return $minor > 0 ? $words.' and '.$formatter->format($minor).' minor units' : $words;
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @return list<string>
     */
    private function addressLines(?array $address): array
    {
        if ($address === null) {
            return [];
        }

        $names = [];

        foreach (['city_id' => 'cities', 'state_id' => 'states', 'country_id' => 'countries'] as $key => $table) {
            if (is_numeric($address[$key] ?? null)) {
                $names[] = DB::connection('landlord')->table($table)->where('id', (int) $address[$key])->value('name');
            }
        }

        return array_values(array_filter([
            $address['name'] ?? null,
            $address['line1'] ?? $address['address_line1'] ?? null,
            $address['line2'] ?? $address['address_line2'] ?? null,
            implode(', ', array_filter([...$names, $address['postal_code'] ?? null])),
            $address['phone'] ?? null,
        ], static fn (mixed $v): bool => is_string($v) && trim($v) !== ''));
    }

    /**
     * The logo as a data URI: dompdf never fetches remote resources.
     */
    private function logo(?int $mediaId): ?string
    {
        $media = $mediaId === null ? null : Media::query()->find($mediaId);

        if ($media === null || ! str_starts_with((string) $media->mime_type, 'image/')) {
            return null;
        }

        try {
            $contents = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        } catch (Throwable) {
            return null;
        }

        return $contents === null ? null : 'data:'.$media->mime_type.';base64,'.base64_encode($contents);
    }
}
