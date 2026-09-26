<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use Closure;
use Illuminate\Support\Collection;

/**
 * Promotion previews on storefront product cards ("20% off", spec §37.6).
 * The Promotions module registers the resolver; without it, cards carry
 * no promotion.
 */
final class ProductPromotions
{
    /** @var (Closure(Collection<int, Product>): array<int, array<string, mixed>>)|null */
    private ?Closure $resolver = null;

    /**
     * @param  Closure(Collection<int, Product>): array<int, array<string, mixed>>  $resolver  product id => preview
     */
    public function useResolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    public function forProducts(Collection $products): array
    {
        return $this->resolver === null || $products->isEmpty() ? [] : ($this->resolver)($products);
    }
}
