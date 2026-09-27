<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates the lines of a purchase order or quotation request: stock-
 * holding products only (simple, or a variable product's own variant, A-32),
 * positive quantities, one line per product and variant.
 */
final class PurchaseLines
{
    public const int MAX_LINES = 200;

    /**
     * @param  list<array<string, mixed>>  $items  product_id, product_variant_id?, quantity, unit_cost?
     * @param  int|null  $supplierId  a missing unit_cost falls back to the supplier's cost, then the product's
     * @return list<array{product: Product, variant: ProductVariant|null, quantity: string, unit_cost: string|null}>
     */
    public static function resolve(array $items, bool $withCost, ?int $supplierId = null): array
    {
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit_cost' => [$withCost ? 'sometimes' : 'prohibited', 'nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
        ])->validate();

        $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');
        $costs = $supplierId === null ? collect() : SupplierProduct::query()->where('supplier_id', $supplierId)->pluck('cost_price', 'product_id');
        $lines = [];
        $seen = [];
        $errors = [];

        foreach ($items as $i => $item) {
            /** @var Product|null $product */
            $product = $products->get((int) $item['product_id']);
            $variantId = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;

            if ($product === null || ! in_array($product->product_type, [Product::SIMPLE, Product::VARIABLE], true)) {
                $errors["items.{$i}.product_id"][] = 'Choose a stock-holding product (simple or variable).';

                continue;
            }

            $variant = null;

            if ($product->product_type === Product::VARIABLE) {
                $variant = $variantId === null ? null : ProductVariant::query()->where('product_id', $product->id)->find($variantId);

                if ($variant === null) {
                    $errors["items.{$i}.product_variant_id"][] = 'Choose one of this product\'s variants.';

                    continue;
                }
            } elseif ($variantId !== null) {
                $errors["items.{$i}.product_variant_id"][] = 'This product has no variants.';

                continue;
            }

            $key = $product->id.':'.($variant?->id ?? 0);

            if (isset($seen[$key])) {
                $errors["items.{$i}.product_id"][] = 'Each product and variant appears once; add up the quantity instead.';

                continue;
            }

            $seen[$key] = true;
            $cost = null;

            if ($withCost) {
                $given = $item['unit_cost'] ?? null;
                $fallback = $costs->get($product->id) ?? $variant?->cost_price ?? $product->cost_price;
                $cost = $given !== null ? (string) $given : ($fallback === null ? null : (string) $fallback);

                if ($cost === null) {
                    $errors["items.{$i}.unit_cost"][] = 'Enter the unit cost (the product has no supplier or catalogue cost).';

                    continue;
                }

                $cost = Money::normalize($cost);
            }

            $lines[] = ['product' => $product, 'variant' => $variant, 'quantity' => Quantity::normalize((string) $item['quantity']), 'unit_cost' => $cost];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }
}
