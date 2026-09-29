<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\SalesAgents\Services\SalesAgentService;
use App\Modules\SalesQuotations\Models\SalesQuotation;
use App\Modules\SalesQuotations\Models\SalesQuotationItem;
use App\Modules\SalesQuotations\Models\SalesQuotationRequest;
use App\Modules\SalesQuotations\Models\SalesQuotationRequestItem;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sales quotations (spec §53): a request, one priced quotation, and on
 * acceptance an ordinary pending order at the quoted prices. Promotions
 * never apply to a converted quote: it is the negotiated price.
 */
final readonly class SalesQuotationService
{
    public const string ENTITY = 'sales_quotation';

    public function __construct(
        private InventoryService $inventory,
        private CurrencyService $currencies,
        private TenantSettingsService $settings,
        private OrderService $orders,
        private TaxService $tax,
        private SalesAgentService $agents,
        private NotificationDispatchService $notifications,
        private CustomFieldService $customFields,
    ) {}

    /**
     * A customer's own request (then $by is null), or one staff start on a
     * customer's behalf, optionally held as a draft.
     *
     * @param  array<string, mixed>  $data  items[{product_id, variant_id?, quantity}], notes?, currency_code?, sales_agent_code? | sales_agent_id?, draft? (staff)
     */
    public function createRequest(?Customer $customer, array $data, ?User $by = null): SalesQuotationRequest
    {
        $validated = Validator::make($data, [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:1000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'sales_agent_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sales_agent_id' => [$by === null ? 'prohibited' : 'sometimes', 'nullable', 'integer'],
            'draft' => [$by === null ? 'prohibited' : 'sometimes', 'boolean'],
        ])->validate();

        $currency = strtoupper((string) ($validated['currency_code'] ?? $this->currencies->baseCurrency()));

        if (! $this->currencies->offered($currency)) {
            throw ApiException::unprocessable('currency_not_supported', 'Quotations are available in the store currency or an offered currency.');
        }

        $agent = $this->agents->resolveForSale($validated['sales_agent_code'] ?? null, isset($validated['sales_agent_id']) ? (int) $validated['sales_agent_id'] : null);
        $lines = $this->resolveLines($validated['items']);

        return DB::connection('tenant')->transaction(function () use ($customer, $validated, $by, $currency, $agent, $lines): SalesQuotationRequest {
            $request = new SalesQuotationRequest;
            $request->forceFill([
                'customer_id' => $customer?->id,
                'sales_agent_id' => $agent?->id,
                'status' => ($validated['draft'] ?? false) ? SalesQuotationRequest::DRAFT : SalesQuotationRequest::SENT,
                'currency_code' => $currency,
                'notes' => $validated['notes'] ?? null,
                'requested_at' => now(),
                'created_by_user_id' => $by?->id,
            ])->save();

            foreach ($lines as [$product, $variant, $quantity]) {
                $item = new SalesQuotationRequestItem;
                $item->forceFill([
                    'sales_quotation_request_id' => $request->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity_requested' => $quantity,
                ])->save();
            }

            return $this->getRequest($request);
        });
    }

    /**
     * Staff price every requested line (§53.2): unit_price and an optional
     * line discount. With allow_quotation_without_stock off, a line with no
     * available stock is refused. The quote is a price commitment only.
     *
     * @param  array<string, mixed>  $data  items[{request_item_id, unit_price, discount_amount?}], valid_until?, notes?, custom_fields?
     */
    public function sendQuotation(SalesQuotationRequest $request, array $data, User $by): SalesQuotation
    {
        $validated = Validator::make($data, [
            'items' => ['required', 'array', 'min:1'],
            'items.*.request_item_id' => ['required', 'integer', 'distinct'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'items.*.discount_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $custom = $this->customFields->validate(self::ENTITY, (array) ($data['custom_fields'] ?? []), CustomFieldService::ADMIN, true);

        $quotation = DB::connection('tenant')->transaction(function () use ($request, $validated, $custom, $by): SalesQuotation {
            /** @var SalesQuotationRequest $locked */
            $locked = SalesQuotationRequest::query()->with(['items.product', 'items.variant'])->lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [SalesQuotationRequest::DRAFT, SalesQuotationRequest::SENT], true)) {
                throw ApiException::invalidTransition($locked->status, SalesQuotationRequest::QUOTED);
            }

            $priced = collect($validated['items'])->keyBy('request_item_id');
            $missing = $locked->items->reject(static fn (SalesQuotationRequestItem $i): bool => $priced->has($i->id));
            $unknown = $priced->keys()->diff($locked->items->pluck('id'));

            if ($missing->isNotEmpty() || $unknown->isNotEmpty()) {
                throw ApiException::unprocessable('quotation_lines_mismatch', 'Price every requested line, and only those.', [
                    'missing_request_item_ids' => $missing->pluck('id')->values()->all(),
                    'unknown_request_item_ids' => $unknown->values()->all(),
                ]);
            }

            if (! (bool) $this->settings->get('allow_quotation_without_stock', true)) {
                $short = $locked->items->filter(fn (SalesQuotationRequestItem $i): bool => $i->product->isPhysical()
                    && $this->inventory->selectFulfillmentWarehouse($i->product, $i->variant, (string) $i->quantity_requested) === null);

                if ($short->isNotEmpty()) {
                    throw ApiException::unprocessable('quotation_out_of_stock', 'Some lines have no available stock.', ['request_item_ids' => $short->pluck('id')->values()->all()]);
                }
            }

            $currency = $locked->currency_code;
            $subtotal = $discount = Money::normalize(0);
            $rows = [];

            foreach ($locked->items as $item) {
                $line = $priced->get($item->id);
                $unit = Money::round(Money::normalize((string) $line['unit_price']), $currency);
                $lineSubtotal = Money::round(bcmul($unit, (string) $item->quantity_requested, 10), $currency);
                $lineDiscount = isset($line['discount_amount']) ? Money::round(Money::normalize((string) $line['discount_amount']), $currency) : null;

                if ($lineDiscount !== null && Money::cmp($lineDiscount, $lineSubtotal) > 0) {
                    throw ApiException::unprocessable('discount_exceeds_line', 'A line discount cannot exceed the line amount.', ['request_item_id' => $item->id]);
                }

                $subtotal = Money::add($subtotal, $lineSubtotal);
                $discount = Money::add($discount, $lineDiscount ?? '0');
                $rows[] = ['sales_quotation_request_item_id' => $item->id, 'unit_price' => $unit, 'discount_amount' => $lineDiscount];
            }

            $quotation = new SalesQuotation;
            $quotation->forceFill([
                'sales_quotation_request_id' => $locked->id,
                'quotation_number' => $this->nextNumber(),
                'status' => SalesQuotation::SENT,
                'currency_code' => $currency,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'valid_until' => $validated['valid_until'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'sent_at' => now(),
                'sent_by_user_id' => $by->id,
            ])->save();

            foreach ($rows as $row) {
                $item = new SalesQuotationItem;
                $item->forceFill(['sales_quotation_id' => $quotation->id, ...$row])->save();
            }

            $this->customFields->save($quotation, self::ENTITY, $custom);
            $locked->forceFill(['status' => SalesQuotationRequest::QUOTED])->save();

            return $quotation;
        });

        $request->refresh()->loadMissing('customer');

        if ($request->customer !== null) {
            $tenant = tenant();
            $this->notifications->dispatch('sales_quotation.sent', $request, [
                'customer_name' => $request->customer->name,
                'quotation_number' => $quotation->quotation_number,
                'total' => Money::format($quotation->total(), $quotation->currency_code),
                'valid_until' => $quotation->valid_until?->toDateString() ?? 'further notice',
                'quotation_url' => $tenant instanceof Tenant ? FrontendUrl::storefront($tenant, 'account/quotations/'.$request->id) : '',
            ]);
        }

        return $quotation;
    }

    /**
     * Acceptance (§53.2) makes a pending order through createOrder: the
     * quoted prices (price_source quotation), no promotions, tax at
     * conversion on the chosen or default address, stock reserved, the
     * request's agent attributed. The order does not expire unpaid: a
     * quoted B2B sale is often paid by transfer days later.
     */
    public function acceptQuotation(SalesQuotation $quotation, string $source, ?User $by = null, ?int $addressId = null): Order
    {
        return DB::connection('tenant')->transaction(function () use ($quotation, $source, $by, $addressId): Order {
            /** @var SalesQuotation $locked */
            $locked = SalesQuotation::query()->with(['items.requestItem.product', 'items.requestItem.variant', 'request.customer'])->lockForUpdate()->findOrFail($quotation->id);
            $this->assertOpen($locked);
            $request = $locked->request;
            $customer = $request->customer;
            $currency = $locked->currency_code;
            $rate = $this->currencies->rateFor($currency) ?? throw ApiException::unprocessable('currency_not_supported', 'The quotation currency has no exchange rate now.');
            $address = $this->address($customer, $addressId);
            $taxAddress = $address === null ? null : ['country_id' => $address->country_id, 'state_id' => $address->state_id];
            $lines = [];

            foreach ($locked->items as $item) {
                $product = $item->requestItem->product;
                $variant = $item->requestItem->variant;
                $quantity = (string) $item->requestItem->quantity_requested;

                if (! CheckoutService::sellable($product, $variant)) {
                    throw ApiException::unprocessable('item_unavailable', "{$product->name} can no longer be sold.");
                }

                $warehouse = $product->isPhysical() ? $this->inventory->selectFulfillmentWarehouse($product, $variant, $quantity) : null;

                if ($product->isPhysical() && $warehouse === null) {
                    throw ApiException::conflict('stock_conflict', "{$product->name} is not in stock for this quantity.");
                }

                $lineSubtotal = Money::round(bcmul((string) $item->unit_price, $quantity, 10), $currency);
                $lines[] = [
                    'product' => $product, 'variant' => $variant, 'warehouse' => $warehouse, 'quantity' => $quantity,
                    'unit_price' => (string) $item->unit_price, 'price_source' => 'quotation',
                    'discount_amount' => Money::normalize((string) ($item->discount_amount ?? '0')), 'line_subtotal' => $lineSubtotal,
                ];
            }

            $inclusive = (bool) $this->settings->get('prices_include_tax', false);
            $taxTotal = Money::normalize(0);

            if ($taxAddress !== null) {
                $taxed = $this->tax->calculateForLines(array_map(static fn (array $l): array => [
                    'amount' => Money::sub($l['line_subtotal'], $l['discount_amount']),
                    'tax_class' => (string) ($l['product']->tax_class ?: 'standard'),
                    'origin' => $l['warehouse'],
                ], $lines), $taxAddress, null, '0', $currency);

                foreach ($lines as $i => $line) {
                    $lines[$i] += ['tax_rate_applied' => $taxed['lines'][$i]['tax_rate_applied'], 'tax_amount' => $taxed['lines'][$i]['tax_amount'], 'tax_breakdown' => $taxed['lines'][$i]['tax_breakdown']];
                }

                $taxTotal = $taxed['total'];
            }

            $subtotal = $discount = Money::normalize(0);

            foreach ($lines as $i => $line) {
                $net = Money::sub($line['line_subtotal'], $line['discount_amount']);
                $lines[$i]['line_total'] = $inclusive ? $net : Money::add($net, $line['tax_amount'] ?? '0');
                $subtotal = Money::add($subtotal, $line['line_subtotal']);
                $discount = Money::add($discount, $line['discount_amount']);
            }

            $total = Money::sub($subtotal, $discount);
            $total = $inclusive ? $total : Money::add($total, $taxTotal);
            $snapshot = $address === null ? null : [
                'name' => $address->recipient_name, 'phone' => $address->phone, 'line1' => $address->address_line_1, 'line2' => $address->address_line_2,
                'city_id' => $address->city_id, 'state_id' => $address->state_id, 'country_id' => $address->country_id, 'postal_code' => $address->postal_code,
            ];

            $order = $this->orders->createOrder([
                'order_source' => $source,
                'status' => Order::PENDING,
                'customer' => $customer,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'prices_include_tax' => $inclusive,
                'lines' => $lines,
                'totals' => ['subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => $taxTotal, 'total' => $total],
                'shipping_address' => $snapshot,
                'billing_address' => $snapshot,
                'sales_agent_id' => $request->sales_agent_id,
                'created_by_user_id' => $by?->id,
                'customer_note' => 'Quotation '.$locked->quotation_number,
            ]);

            $locked->forceFill(['status' => SalesQuotation::ACCEPTED, 'responded_at' => now(), 'converted_order_id' => $order->id])->save();
            $request->forceFill(['status' => SalesQuotationRequest::CONVERTED])->save();

            return $order;
        });
    }

    public function rejectQuotation(SalesQuotation $quotation): SalesQuotation
    {
        return DB::connection('tenant')->transaction(function () use ($quotation): SalesQuotation {
            /** @var SalesQuotation $locked */
            $locked = SalesQuotation::query()->with('request')->lockForUpdate()->findOrFail($quotation->id);
            $this->assertOpen($locked);

            $locked->forceFill(['status' => SalesQuotation::REJECTED, 'responded_at' => now()])->save();
            $locked->request->forceFill(['status' => SalesQuotationRequest::REJECTED])->save();

            return $locked;
        });
    }

    /**
     * Before a quote is sent (§53.1): a quoted request is answered by
     * accepting or rejecting its quote instead.
     */
    public function cancelRequest(SalesQuotationRequest $request): SalesQuotationRequest
    {
        return DB::connection('tenant')->transaction(function () use ($request): SalesQuotationRequest {
            /** @var SalesQuotationRequest $locked */
            $locked = SalesQuotationRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [SalesQuotationRequest::DRAFT, SalesQuotationRequest::SENT], true)) {
                throw ApiException::invalidTransition($locked->status, SalesQuotationRequest::CANCELLED);
            }

            $locked->forceFill(['status' => SalesQuotationRequest::CANCELLED])->save();

            return $locked;
        });
    }

    /**
     * ExpireSalesQuotations (§53.2): sent quotes past valid_until.
     */
    public function expireQuotations(): int
    {
        $expired = 0;

        SalesQuotation::query()->where('status', SalesQuotation::SENT)->whereNotNull('valid_until')->whereDate('valid_until', '<', today())
            ->orderBy('id')->get()->each(function (SalesQuotation $quotation) use (&$expired): void {
                DB::connection('tenant')->transaction(function () use ($quotation, &$expired): void {
                    $updated = SalesQuotation::query()->whereKey($quotation->id)->where('status', SalesQuotation::SENT)
                        ->update(['status' => SalesQuotation::EXPIRED, 'updated_at' => now()]);

                    if ($updated > 0) {
                        SalesQuotationRequest::query()->whereKey($quotation->sales_quotation_request_id)->where('status', SalesQuotationRequest::QUOTED)
                            ->update(['status' => SalesQuotationRequest::EXPIRED, 'updated_at' => now()]);
                        $expired++;
                    }
                });
            });

        return $expired;
    }

    public function getRequest(SalesQuotationRequest $request): SalesQuotationRequest
    {
        return $request->load(['items.product:id,name,sku,slug,product_type', 'items.variant:id,sku', 'customer:id,name,email', 'salesAgent:id,name,agent_code', 'quotation.items']);
    }

    /**
     * @param  array{status?: string, customer_id?: int, sales_agent_id?: int, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, SalesQuotationRequest>
     */
    public function listRequests(array $filters = []): LengthAwarePaginator
    {
        return SalesQuotationRequest::query()->with(['customer:id,name,email', 'quotation'])
            ->withCount('items')
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), static fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['sales_agent_id']), static fn ($q) => $q->where('sales_agent_id', $filters['sales_agent_id']))
            ->when(isset($filters['search']), static fn ($q) => $q->whereHas('quotation', static fn ($w) => $w->where('quotation_number', strtoupper((string) $filters['search']))))
            ->orderByDesc('requested_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * A sent quote past its valid_until can no longer be answered, even
     * before the daily job marks it expired.
     */
    private function assertOpen(SalesQuotation $quotation): void
    {
        if ($quotation->status === SalesQuotation::SENT && $quotation->valid_until !== null && $quotation->valid_until->lt(CarbonImmutable::today())) {
            throw ApiException::unprocessable('quotation_expired', 'This quotation has expired.');
        }

        if ($quotation->status !== SalesQuotation::SENT) {
            throw ApiException::invalidTransition($quotation->status, SalesQuotation::ACCEPTED);
        }
    }

    private function address(?Customer $customer, ?int $addressId): ?Address
    {
        if ($customer === null) {
            return null;
        }

        if ($addressId !== null) {
            return Address::query()->where('customer_id', $customer->id)->find($addressId)
                ?? throw ApiException::unprocessable('address_invalid', 'Choose one of the customer\'s saved addresses.');
        }

        return Address::query()->where('customer_id', $customer->id)->where('is_default', true)->first();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{0: Product, 1: ProductVariant|null, 2: string}>
     */
    private function resolveLines(array $items): array
    {
        $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');
        $variants = ProductVariant::query()->whereKey(array_filter(array_column($items, 'variant_id')))->get()->keyBy('id');
        $lines = [];

        foreach ($items as $i => $item) {
            $product = $products->get((int) $item['product_id']);
            $variant = isset($item['variant_id']) ? $variants->get((int) $item['variant_id']) : null;

            if ($product === null || ! CheckoutService::sellable($product, $variant)) {
                throw ApiException::unprocessable('item_unavailable', 'A requested product cannot be quoted.', ['line' => $i]);
            }

            $lines[] = [$product, $variant, Quantity::normalize((string) $item['quantity'])];
        }

        return $lines;
    }

    /**
     * SQ-000001, from a locked counter row (never reused).
     */
    private function nextNumber(): string
    {
        $row = DB::connection('tenant')->table('sequences')->where('name', 'sales_quotation_number')->lockForUpdate()->first();

        if ($row === null) {
            DB::connection('tenant')->table('sequences')->insert(['name' => 'sales_quotation_number', 'next_value' => 2, 'created_at' => now(), 'updated_at' => now()]);
            $value = 1;
        } else {
            $value = (int) $row->next_value;
            DB::connection('tenant')->table('sequences')->where('name', 'sales_quotation_number')->update(['next_value' => $value + 1, 'updated_at' => now()]);
        }

        return 'SQ-'.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
