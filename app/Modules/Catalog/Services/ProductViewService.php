<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Jobs\RecordProductView;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Product views (spec §29.6): each storefront detail request increments
 * view_count and adds a product_views row, off the request (queued).
 * "trending" ranks by views in the last 7 days (Assumption A-13).
 */
final readonly class ProductViewService
{
    public const int TRENDING_DAYS = 7;

    public function recordView(Product $product, ?Customer $customer): void
    {
        RecordProductView::dispatch((string) tenant()?->getTenantKey(), $product->id, $customer?->id, now()->toIso8601String());
    }

    public function store(int $productId, ?int $customerId, Carbon $viewedAt): void
    {
        DB::connection('tenant')->transaction(static function () use ($productId, $customerId, $viewedAt): void {
            if (DB::connection('tenant')->table('products')->where('id', $productId)->increment('view_count') === 0) {
                return;
            }

            DB::connection('tenant')->table('product_views')->insert([
                'product_id' => $productId,
                'customer_id' => $customerId,
                'viewed_at' => $viewedAt->utc(),
            ]);
        });
    }

    /**
     * @return Collection<int, Product>
     */
    public function getTrending(int $days = self::TRENDING_DAYS, int $limit = 20): Collection
    {
        return Product::query()->visible()
            ->select('products.*')
            ->joinSub(self::trendingCounts($days), 'trend', 'trend.product_id', '=', 'products.id')
            ->orderByDesc('trend.views')
            ->limit($limit)
            ->get();
    }

    /**
     * Views per product in the window, for joins and sorting.
     */
    public static function trendingCounts(int $days = self::TRENDING_DAYS): \Illuminate\Database\Query\Builder
    {
        return DB::connection('tenant')->table('product_views')
            ->where('viewed_at', '>=', now()->subDays($days))
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as views');
    }

    /**
     * Retention of view rows beyond the trending window (tenant daily
     * maintenance, §72.2). view_count keeps the lifetime total.
     */
    public function purgeOld(int $keepDays = 90): int
    {
        $deleted = 0;

        do {
            $ids = DB::connection('tenant')->table('product_views')->where('viewed_at', '<', now()->subDays($keepDays))->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::connection('tenant')->table('product_views')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $deleted;
    }
}
