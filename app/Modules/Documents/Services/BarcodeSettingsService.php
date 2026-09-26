<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Documents\Contracts\DocumentRenderer;
use App\Modules\Documents\Models\BarcodeSetting;
use App\Modules\Documents\Support\RenderedDocument;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehousePricingService;
use App\Modules\Promotions\Support\FlashSalePrices;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sticker-sheet layouts and the sheet generator (spec §43.4). Label content
 * comes from the catalogue; the barcode value is the variant barcode, then
 * the product barcode, then the SKU.
 */
final readonly class BarcodeSettingsService
{
    /** Upper bound on labels per request, so one call cannot exhaust memory. */
    public const int MAX_LABELS = 2000;

    public const array LABEL_TOGGLES = ['show_product_name', 'show_price', 'show_promotional_price', 'show_business_name', 'show_brand', 'show_size', 'show_barcode_value'];

    public const array FONT_SIZES = ['name_font_size', 'price_font_size', 'promotional_price_font_size', 'business_name_font_size', 'brand_font_size', 'size_font_size'];

    public function __construct(
        private DocumentRenderer $renderer,
        private WarehousePricingService $warehousePrices,
        private FlashSalePrices $flashSales,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $inches = [$required, 'numeric', 'min:0', 'max:60'];

        return [
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'label_layout' => [$required, Rule::in(['continuous', 'dymo'])],
            'top_margin_inches' => $inches,
            'left_margin_inches' => $inches,
            'sticker_width_inches' => [$required, 'numeric', 'gt:0', 'max:60'],
            'sticker_height_inches' => [$required, 'numeric', 'gt:0', 'max:60'],
            'paper_width_inches' => [$required, 'numeric', 'gt:0', 'max:60'],
            'paper_height_inches' => [$required, 'numeric', 'gt:0', 'max:60'],
            'row_distance_inches' => $inches,
            'column_distance_inches' => $inches,
            'stickers_per_row' => [$required, 'integer', 'min:1', 'max:20'],
            'stickers_per_sheet' => [$required, 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function labelRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('tenant.product_variants', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_LABELS],
            'barcode_setting_id' => ['nullable', 'integer', Rule::exists('tenant.barcode_settings', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('tenant.warehouses', 'id')],
            ...array_fill_keys(self::LABEL_TOGGLES, ['sometimes', 'boolean']),
            ...array_fill_keys(self::FONT_SIZES, ['sometimes', 'integer', 'min:5', 'max:36']),
        ];
    }

    /**
     * @return Collection<int, BarcodeSetting>
     */
    public function listSettings(): Collection
    {
        return BarcodeSetting::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function createSetting(array $data): BarcodeSetting
    {
        return DB::connection('tenant')->transaction(function () use ($data): BarcodeSetting {
            $setting = new BarcodeSetting($data);
            $this->assertFits($setting);
            $setting->forceFill(['is_default' => ! BarcodeSetting::query()->where('is_default', true)->exists()])->save();

            return $setting;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function updateSetting(BarcodeSetting $setting, array $data): BarcodeSetting
    {
        $setting->fill($data);
        $this->assertFits($setting);
        $setting->save();

        return $setting;
    }

    public function deleteSetting(BarcodeSetting $setting): void
    {
        DB::connection('tenant')->transaction(static function () use ($setting): void {
            if (BarcodeSetting::query()->lockForUpdate()->count() <= 1) {
                throw ApiException::unprocessable('last_barcode_setting', 'At least one sticker layout must remain.');
            }

            $setting->delete();

            if ($setting->is_default) {
                BarcodeSetting::query()->orderBy('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });
    }

    public function setDefaultSetting(BarcodeSetting $setting): BarcodeSetting
    {
        DB::connection('tenant')->transaction(static function () use ($setting): void {
            BarcodeSetting::query()->where('is_default', true)->whereKeyNot($setting->id)->lockForUpdate()->update(['is_default' => false]);
            $setting->forceFill(['is_default' => true])->save();
        });

        return $setting;
    }

    /**
     * Lays labels out on the chosen or default layout as a PDF.
     *
     * @param  list<array{product_id: int, product_variant_id?: int|null, quantity: int}>  $items
     * @param  array<string, mixed>  $labelOptions  toggles, font sizes, optional warehouse_id
     */
    public function generateStickerSheet(array $items, ?BarcodeSetting $setting = null, array $labelOptions = []): RenderedDocument
    {
        $setting ??= BarcodeSetting::query()->where('is_default', true)->first()
            ?? throw ApiException::unprocessable('no_barcode_setting', 'Create a sticker layout first.');

        if (array_sum(array_map(static fn (array $i): int => (int) $i['quantity'], $items)) > self::MAX_LABELS) {
            throw ApiException::unprocessable('too_many_labels', 'Print at most '.self::MAX_LABELS.' labels at a time.');
        }

        $warehouse = isset($labelOptions['warehouse_id']) ? Warehouse::query()->find((int) $labelOptions['warehouse_id']) : null;
        $options = $this->options($labelOptions);
        $labels = [];

        foreach ($this->labelContent($items, $warehouse, $options) as [$label, $quantity]) {
            array_push($labels, ...array_fill(0, $quantity, $label));
        }

        $perSheet = $setting->label_layout === 'dymo' ? 1 : $setting->stickers_per_sheet;
        $paper = $setting->label_layout === 'dymo'
            ? [(float) $setting->sticker_width_inches * 72, (float) $setting->sticker_height_inches * 72]
            : [(float) $setting->paper_width_inches * 72, (float) $setting->paper_height_inches * 72];

        return new RenderedDocument($this->renderer->pdf('documents.stickers', [
            'setting' => $setting,
            'dymo' => $setting->label_layout === 'dymo',
            'sheets' => array_chunk($labels, $perSheet),
            'options' => $options,
        ], $paper), 'application/pdf', 'labels.pdf');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool|int>
     */
    private function options(array $input): array
    {
        $defaults = ['show_product_name' => true, 'show_price' => true, 'show_promotional_price' => false, 'show_business_name' => false,
            'show_brand' => false, 'show_size' => false, 'show_barcode_value' => true];
        $options = [];

        foreach ($defaults as $key => $default) {
            $options[$key] = (bool) ($input[$key] ?? $default);
        }

        foreach (self::FONT_SIZES as $key) {
            $options[$key] = (int) ($input[$key] ?? ($key === 'name_font_size' || $key === 'price_font_size' ? 9 : 8));
        }

        return $options;
    }

    /**
     * @param  list<array{product_id: int, product_variant_id?: int|null, quantity: int}>  $items
     * @param  array<string, bool|int>  $options
     * @return list<array{0: array<string, string|null>, 1: int}>
     */
    private function labelContent(array $items, ?Warehouse $warehouse, array $options): array
    {
        $products = Product::query()->with('brand:id,name')->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $variantIds = array_values(array_filter(array_map(static fn (array $i): ?int => $i['product_variant_id'] ?? null, $items)));
        $variants = ProductVariant::query()->with('optionValues.option:id,name')->whereIn('id', $variantIds)->get()->keyBy('id');
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $business = $options['show_business_name'] ? (string) $this->settings->get('store_name', '') : null;
        $result = [];

        foreach ($items as $item) {
            /** @var Product|null $product */
            $product = $products->get($item['product_id']);
            $variantId = $item['product_variant_id'] ?? null;
            /** @var ProductVariant|null $variant */
            $variant = $variantId === null ? null : $variants->get($variantId);

            if ($product === null || ($variantId !== null && $variant?->product_id !== $product->id)) {
                throw ApiException::unprocessable('invalid_label_item', 'A label item does not match its product.');
            }

            $code = $variant?->barcode ?? $product->barcode ?? $variant?->sku ?? $product->sku;

            if ($code === null || $code === '') {
                throw ApiException::unprocessable('no_barcode', "\"{$product->name}\" has no barcode or SKU to print.");
            }

            $price = Money::normalize((string) ($variant?->price ?? $product->price));

            if ($warehouse !== null && ($row = $this->warehousePrices->getPriceForWarehouse($product, $warehouse, $variant)) !== null) {
                $price = $row['price'];
            }

            $sale = $options['show_promotional_price'] ? $this->flashSales->priceFor($product->id) : null;
            $size = $variant?->optionValues->first(static fn (ProductOptionValue $v): bool => strcasecmp($v->option->name, 'size') === 0)?->value;

            $result[] = [[
                'name' => $options['show_product_name'] ? $product->name : null,
                'price' => $options['show_price'] ? Money::format(Money::round($price, $currency), $currency) : null,
                'promotional_price' => $sale !== null && Money::cmp($sale, $price) < 0 ? Money::format(Money::round($sale, $currency), $currency) : null,
                'business' => $business,
                'brand' => $options['show_brand'] ? $product->brand?->name : null,
                'size' => $options['show_size'] ? $size : null,
                'code' => $code,
                'barcode' => $this->renderer->barcode($code, 1.2, 32),
            ], (int) $item['quantity']];
        }

        return $result;
    }

    /**
     * The stickers of a row must fit across the paper.
     */
    private function assertFits(BarcodeSetting $setting): void
    {
        if ($setting->label_layout === 'dymo') {
            return;
        }

        $width = (float) $setting->left_margin_inches + $setting->stickers_per_row * (float) $setting->sticker_width_inches
            + ($setting->stickers_per_row - 1) * (float) $setting->column_distance_inches;

        if ($width > (float) $setting->paper_width_inches + 0.001) {
            throw ApiException::unprocessable('stickers_do_not_fit', 'The stickers of one row are wider than the paper.');
        }

        if ($setting->stickers_per_sheet < $setting->stickers_per_row) {
            throw ApiException::unprocessable('stickers_do_not_fit', 'A sheet must hold at least one full row.');
        }
    }
}
