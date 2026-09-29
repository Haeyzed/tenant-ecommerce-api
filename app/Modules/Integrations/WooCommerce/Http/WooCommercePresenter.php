<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Http;

use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSyncLog;

/**
 * WooCommerce responses (spec §68.4). The consumer key and secret are
 * never returned, only whether they are set (§5.6).
 */
final readonly class WooCommercePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function settings(WooCommerceSettings $settings): array
    {
        return [
            'store_url' => $settings->store_url,
            'has_consumer_key' => $settings->consumer_key !== null,
            'has_consumer_secret' => $settings->consumer_secret !== null,
            'is_active' => $settings->is_active,
            'sync_products' => $settings->sync_products,
            'sync_categories' => $settings->sync_categories,
            'sync_orders' => $settings->sync_orders,
            'sync_tax_rates' => $settings->sync_tax_rates,
            'order_sync_direction' => $settings->order_sync_direction,
            'import_warehouse_id' => $settings->import_warehouse_id,
            'sync_interval_minutes' => $settings->sync_interval_minutes,
            'activated_at' => $settings->activated_at?->toIso8601String(),
            'last_synced_at' => $settings->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function log(WooCommerceSyncLog $log): array
    {
        return [
            'id' => $log->id,
            'sync_type' => $log->sync_type,
            'direction' => $log->direction,
            'trigger' => $log->trigger,
            'status' => $log->status,
            'items_processed' => $log->items_processed,
            'items_failed' => $log->items_failed,
            'error_details' => $log->error_details ?? [],
            'started_at' => $log->started_at->toIso8601String(),
            'completed_at' => $log->completed_at?->toIso8601String(),
        ];
    }
}
