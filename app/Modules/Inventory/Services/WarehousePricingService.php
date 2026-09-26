<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseProductPrice;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Per-warehouse prices (spec §34). Read only when the product opts in with
 * has_warehouse_pricing; a warehouse without a row falls back to the base
 * price. Checkout resolves the price once the fulfilling warehouse is known
 * (§38.4).
 */
final readonly class WarehousePricingService
{
    public function setWarehousePrice(Product $product, Warehouse $warehouse, string $price, ?string $compareAtPrice = null, ?ProductVariant $variant = null): WarehouseProductPrice
    {
        $this->validatePrices($price, $compareAtPrice);

        if ($variant !== null && $variant->product_id !== $product->id) {
            throw ValidationException::withMessages(['product_variant_id' => ['The variant does not belong to this product.']]);
        }

        if ($variant !== null && $product->product_type !== Product::VARIABLE) {
            throw ValidationException::withMessages(['product_variant_id' => ['Only variable products have variant prices.']]);
        }

        $exists = WarehouseProductPrice::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)
            ->where('variant_key', $variant?->id ?? 0)->exists();

        if ($exists) {
            throw ApiException::conflict('warehouse_price_exists', 'A price for this warehouse already exists. Update it instead.');
        }

        $row = new WarehouseProductPrice(['price' => Money::normalize($price), 'compare_at_price' => $compareAtPrice !== null ? Money::normalize($compareAtPrice) : null]);
        $row->forceFill(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'warehouse_id' => $warehouse->id])->save();

        return $row->load(['warehouse:id,name', 'variant:id,sku']);
    }

    public function updateWarehousePrice(WarehouseProductPrice $row, string $price, ?string $compareAtPrice): WarehouseProductPrice
    {
        $this->validatePrices($price, $compareAtPrice);
        $row->fill(['price' => Money::normalize($price), 'compare_at_price' => $compareAtPrice !== null ? Money::normalize($compareAtPrice) : null])->save();

        return $row->load(['warehouse:id,name', 'variant:id,sku']);
    }

    public function removeWarehousePrice(WarehouseProductPrice $row): void
    {
        $row->delete();
    }

    /**
     * The variant's row first, then the product's; null when neither exists
     * or the product does not use warehouse pricing.
     *
     * @return array{price: string, compare_at_price: string|null}|null
     */
    public function getPriceForWarehouse(Product $product, Warehouse $warehouse, ?ProductVariant $variant = null): ?array
    {
        if (! $product->has_warehouse_pricing) {
            return null;
        }

        $row = WarehouseProductPrice::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('variant_key', array_values(array_unique([$variant?->id ?? 0, 0])))
            ->orderByDesc('variant_key')
            ->first();

        return $row === null ? null : [
            'price' => (string) $row->price,
            'compare_at_price' => $row->compare_at_price !== null ? (string) $row->compare_at_price : null,
        ];
    }

    /**
     * @return Collection<int, WarehouseProductPrice>
     */
    public function listWarehousePrices(Product $product): Collection
    {
        return WarehouseProductPrice::query()->with(['warehouse:id,name', 'variant:id,sku'])
            ->where('product_id', $product->id)
            ->orderBy('warehouse_id')->orderBy('variant_key')
            ->get();
    }

    private function validatePrices(string $price, ?string $compareAtPrice): void
    {
        validator(['price' => $price, 'compare_at_price' => $compareAtPrice], [
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
        ])->validate();
    }
}
