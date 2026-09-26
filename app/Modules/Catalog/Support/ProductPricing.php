<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Shared\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The storefront's effective price (spec §38.4). The catalogue price is
 * the variant price, else the product price. Price rules that lower it
 * (flash sales §37, per-warehouse pricing §34, currency §48) register an
 * adjuster when they are built; the lowest result wins. Price filtering
 * and sorting use the same definition through the registered query
 * expression, falling back to the catalogue price.
 */
final class ProductPricing
{
    /** @var array<string, Closure(Product, ?ProductVariant, string): string> */
    private array $adjusters = [];

    /**
     * SQL expression of the effective product price, or null for the
     * catalogue price.
     */
    private ?Closure $priceExpression = null;

    /**
     * @param  Closure(Product, ?ProductVariant, string $current): string  $adjuster  returns the adjusted price
     */
    public function registerAdjuster(string $name, Closure $adjuster): void
    {
        $this->adjusters[$name] = $adjuster;
    }

    /**
     * @param  Closure(): string  $expression  SQL of the effective price per products row
     */
    public function usePriceExpression(Closure $expression): void
    {
        $this->priceExpression = $expression;
    }

    public function catalogPrice(Product $product, ?ProductVariant $variant = null): string
    {
        return Money::normalize((string) ($variant?->price ?? $product->price));
    }

    public function effectivePrice(Product $product, ?ProductVariant $variant = null): string
    {
        $price = $this->catalogPrice($product, $variant);

        foreach ($this->adjusters as $adjuster) {
            $price = Money::min($price, Money::normalize($adjuster($product, $variant, $price)));
        }

        return $price;
    }

    public function compareAtPrice(Product $product, ?ProductVariant $variant = null): ?string
    {
        $value = $variant?->compare_at_price ?? $product->compare_at_price;

        return $value === null ? null : Money::normalize((string) $value);
    }

    public function isOnSale(Product $product, ?ProductVariant $variant = null): bool
    {
        $effective = $this->effectivePrice($product, $variant);
        $compare = $this->compareAtPrice($product, $variant);

        return ($compare !== null && Money::cmp($effective, $compare) < 0)
            || Money::cmp($effective, $this->catalogPrice($product, $variant)) < 0;
    }

    public function priceColumn(): string
    {
        return $this->priceExpression !== null ? ($this->priceExpression)() : 'products.price';
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function applyPriceRange(Builder $query, ?string $min, ?string $max): void
    {
        $column = $this->priceColumn();

        if ($min !== null) {
            $query->whereRaw("{$column} >= ?", [$min]);
        }

        if ($max !== null) {
            $query->whereRaw("{$column} <= ?", [$max]);
        }
    }
}
