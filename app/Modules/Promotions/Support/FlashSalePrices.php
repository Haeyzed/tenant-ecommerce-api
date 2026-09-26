<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The running flash-sale prices (spec §37.7, §38.4 step 4): product id =>
 * the lowest sale price among running sales with remaining quantity.
 * Cached per tenant for a minute and flushed on every flash-sale change;
 * checkout re-checks under lock when it claims (409 price_changed).
 */
final class FlashSalePrices
{
    private const string KEY = 'flash-sales:running-prices';

    private const int TTL_SECONDS = 60;

    /**
     * @return array<int, string>
     */
    public function map(): array
    {
        /** @var array<int, string> */
        return Cache::remember(self::KEY, self::TTL_SECONDS, fn (): array => DB::connection('tenant')->table('flash_sale_products as fsp')
            ->join('flash_sales as fs', 'fs.id', '=', 'fsp.flash_sale_id')
            ->whereRaw($this->runningCondition())
            ->groupBy('fsp.product_id')
            ->selectRaw('fsp.product_id, MIN(fsp.sale_price) as price')
            ->pluck('price', 'product_id')
            ->map(static fn ($price): string => bcadd((string) $price, '0', 4))
            ->all());
    }

    public function priceFor(int $productId): ?string
    {
        return $this->map()[$productId] ?? null;
    }

    public function flush(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * The effective price per products row for storefront sorting and
     * filtering: the catalogue price, lowered by a running sale.
     */
    public function priceExpression(): string
    {
        return 'LEAST(products.price, COALESCE((SELECT MIN(fsp.sale_price) FROM flash_sale_products fsp'
            .' JOIN flash_sales fs ON fs.id = fsp.flash_sale_id'
            .' WHERE fsp.product_id = products.id AND '.$this->runningCondition().'), products.price))';
    }

    /**
     * The timestamp is generated here (UTC, as stored), never user input.
     */
    private function runningCondition(): string
    {
        $now = now()->utc()->format('Y-m-d H:i:s');

        return "fs.is_active = 1 AND fs.starts_at <= '{$now}' AND fs.ends_at > '{$now}'"
            .' AND (fsp.quantity_limit IS NULL OR fsp.quantity_claimed < fsp.quantity_limit)';
    }
}
