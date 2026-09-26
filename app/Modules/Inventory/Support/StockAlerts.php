<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Events\StockReplenished;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The post-mutation hooks of §32.7, on total available stock across active
 * warehouses. The notifications are delivered after commit (templated
 * notifications are after-commit); StockReplenished is raised after commit
 * for back-in-stock alerts (§56), whose listener checks its own feature.
 */
final readonly class StockAlerts
{
    public function __construct(
        private NotificationDispatchService $notifications,
        private TenantSettingsService $settings,
    ) {}

    public function evaluate(Product $product, ?ProductVariant $variant, string $before, string $after): void
    {
        $threshold = Quantity::normalize((int) $this->settings->get('low_stock_threshold', 5));
        $variables = [
            'product_name' => $variant === null ? $product->name : $product->name.' ('.$variant->sku.')',
            'sku' => (string) ($variant?->sku ?? $product->sku ?? '-'),
            'available' => rtrim(rtrim($after, '0'), '.') ?: '0',
            'warehouse_name' => 'all warehouses',
        ];

        if (Quantity::isPositive($before) && ! Quantity::isPositive($after)) {
            $this->notifications->dispatch('inventory.out_of_stock', $product, $variables);
        } elseif (Quantity::cmp($before, $threshold) > 0 && Quantity::cmp($after, $threshold) <= 0) {
            $this->notifications->dispatch('inventory.low_stock', $product, $variables);
        }

        if (! Quantity::isPositive($before) && Quantity::isPositive($after)) {
            $tenantId = (string) tenant()?->getTenantKey();
            DB::connection('tenant')->afterCommit(static fn () => event(new StockReplenished($tenantId, $product->id, $variant?->id)));
        }
    }

    /**
     * The daily expiry check (§32.7): stock-holding products whose
     * expiry_date falls within expiring_product_alert_days. Does nothing
     * while that setting is null.
     *
     * @return int the number of products reported
     */
    public function checkExpiringProducts(): int
    {
        $days = $this->settings->get('expiring_product_alert_days');

        if ($days === null) {
            return 0;
        }

        $count = Product::query()
            ->whereIn('product_type', [Product::SIMPLE, Product::VARIABLE])
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays((int) $days)->toDateString()])
            ->count();

        if ($count > 0) {
            $this->notifications->dispatch('inventory.product_expiring_soon', null, ['count' => $count, 'days' => (int) $days]);
        }

        return $count;
    }
}
