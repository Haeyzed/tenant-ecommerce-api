<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Documents\Models\BarcodeSetting;
use App\Modules\Documents\Support\RenderedDocument;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehousePricingService;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The label-printing screen (spec §43.5): product search in the stock
 * lookup shape, with the on-hand quantity (all warehouses, or one) as the
 * default label count and the current price.
 */
final readonly class PrintBarcodeService
{
    private const int LIMIT = 25;

    public function __construct(
        private BarcodeSettingsService $settings,
        private WarehousePricingService $warehousePrices,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function searchProducts(string $query, ?Warehouse $warehouse = null): Collection
    {
        $term = trim($query);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $types = [Product::SIMPLE, Product::BUNDLE, Product::DIGITAL, Product::SERVICE];

        $simple = Product::query()->whereIn('product_type', $types)
            ->where(static fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('sku', $term)->orWhere('barcode', $term))
            ->orderBy('name')->limit(self::LIMIT)->get();

        $variants = ProductVariant::query()->with('product')
            ->whereHas('product', static fn (Builder $p) => $p->where('product_type', Product::VARIABLE))
            ->where(static fn (Builder $q) => $q->where('sku', $term)->orWhere('barcode', $term)
                ->orWhereHas('product', static fn (Builder $p) => $p->where('name', 'like', $like)))
            ->orderBy('product_id')->orderBy('id')->limit(self::LIMIT)->get();

        $stock = Inventory::query()
            ->when($warehouse !== null, static fn (Builder $q) => $q->where('warehouse_id', $warehouse?->id))
            ->where(static fn (Builder $q) => $q
                ->where(static fn (Builder $s) => $s->whereIn('product_id', $simple->pluck('id'))->where('variant_key', 0))
                ->orWhereIn('product_variant_id', $variants->pluck('id')))
            ->get(['product_id', 'variant_key', 'quantity'])
            ->groupBy(static fn (Inventory $row): string => $row->product_id.':'.$row->variant_key)
            ->map(static fn (Collection $rows): string => $rows->reduce(static fn (string $sum, Inventory $r): string => Quantity::add($sum, (string) $r->quantity), Quantity::normalize(0)));

        $rows = $simple->map(fn (Product $p): array => [
            'product_id' => $p->id,
            'product_variant_id' => null,
            'name' => $p->name,
            'code' => $p->sku ?? $p->barcode,
            'barcode' => $p->barcode,
            'price' => $this->price($p, null, $warehouse),
            'quantity' => $stock->get($p->id.':0', Quantity::normalize(0)),
        ])->concat($variants->map(fn (ProductVariant $v): array => [
            'product_id' => $v->product_id,
            'product_variant_id' => $v->id,
            'name' => $v->product->name.' ('.$v->sku.')',
            'code' => $v->sku,
            'barcode' => $v->barcode ?? $v->product->barcode,
            'price' => $this->price($v->product, $v, $warehouse),
            'quantity' => $stock->get($v->product_id.':'.$v->id, Quantity::normalize(0)),
        ]));

        return $rows->take(self::LIMIT)->values();
    }

    /**
     * @param  list<array{product_id: int, product_variant_id?: int|null, quantity: int}>  $items
     * @param  array<string, mixed>  $labelOptions
     */
    public function generateLabels(array $items, ?BarcodeSetting $setting = null, array $labelOptions = []): RenderedDocument
    {
        return $this->settings->generateStickerSheet($items, $setting, $labelOptions);
    }

    private function price(Product $product, ?ProductVariant $variant, ?Warehouse $warehouse): string
    {
        if ($warehouse !== null && ($row = $this->warehousePrices->getPriceForWarehouse($product, $warehouse, $variant)) !== null) {
            return $row['price'];
        }

        return Money::normalize((string) ($variant?->price ?? $product->price));
    }
}
