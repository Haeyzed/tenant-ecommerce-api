<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Storefront availability ("in_stock", spec §31.1, §31.2). Digital and
 * service products are always in stock. Physical products are in stock
 * only when inventory says so: the Inventory module registers the resolver
 * and the query constraint when warehouses are built (§32). Until then no
 * stock exists anywhere, so physical products are reported out of stock,
 * which is the truth.
 */
final class ProductAvailability
{
    /** @var (Closure(Collection<int, Product>): array<int, bool>)|null */
    private ?Closure $resolver = null;

    /** @var (Closure(Builder<Product>): void)|null */
    private ?Closure $constraint = null;

    /**
     * @param  Closure(Collection<int, Product>): array<int, bool>  $resolver  product id => has available stock (physical products only)
     * @param  Closure(Builder<Product>): void  $constraint  restricts a query to physical products with available stock
     */
    public function useInventory(Closure $resolver, Closure $constraint): void
    {
        $this->resolver = $resolver;
        $this->constraint = $constraint;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, bool> product id => in stock
     */
    public function forProducts(Collection $products): array
    {
        $result = [];
        $physical = $products->filter(static fn (Product $p): bool => $p->isPhysical());
        $stock = $this->resolver !== null && $physical->isNotEmpty() ? ($this->resolver)($physical->values()) : [];

        foreach ($products as $product) {
            $result[$product->id] = $product->isPhysical() ? (bool) ($stock[$product->id] ?? false) : true;
        }

        return $result;
    }

    public function isInStock(Product $product): bool
    {
        return $this->forProducts(new Collection([$product]))[$product->id];
    }

    /**
     * in_stock=1: non-physical products, plus physical ones with stock.
     *
     * @param  Builder<Product>  $query
     */
    public function applyInStockFilter(Builder $query): void
    {
        $constraint = $this->constraint;

        $query->where(static function (Builder $q) use ($constraint): void {
            $q->whereNotIn('products.product_type', Product::PHYSICAL);

            if ($constraint !== null) {
                $q->orWhere(static function (Builder $physical) use ($constraint): void {
                    $physical->whereIn('products.product_type', Product::PHYSICAL);
                    $constraint($physical);
                });
            }
        });
    }
}
