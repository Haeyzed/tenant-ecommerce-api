<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Storefront sort orders (spec §31.2). The catalogue registers the sorts
 * it can compute; best_selling needs paid order lines and is registered by
 * the Orders module when it is built (a sort not registered is rejected by
 * validation).
 */
final class ProductSorts
{
    /** @var array<string, Closure(Builder<Product>): void> */
    private array $sorts = [];

    /**
     * @param  Closure(Builder<Product>): void  $apply
     */
    public function register(string $name, Closure $apply): void
    {
        $this->sorts[$name] = $apply;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->sorts);
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function apply(Builder $query, string $name): void
    {
        ($this->sorts[$name])($query);
    }
}
