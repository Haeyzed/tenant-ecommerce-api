<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Http;

use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceProductMap;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceSyncLog;

/**
 * Social commerce responses (spec §69.3). Tokens are never returned (§5.6).
 */
final readonly class SocialCommercePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function account(SocialCommerceAccount $account): array
    {
        return [
            'id' => $account->id,
            'channel' => $account->channel,
            'has_access_token' => $account->access_token !== '',
            'account_reference' => $account->account_reference,
            'order_account_reference' => $account->order_account_reference,
            'is_active' => $account->is_active,
            'sync_products' => $account->sync_products,
            'sync_orders' => $account->sync_orders,
            'takes_orders' => $account->takesOrders(),
            'fulfilment_warehouse_id' => $account->fulfilment_warehouse_id,
            'sync_interval_minutes' => $account->sync_interval_minutes,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listing(SocialCommerceProductMap $map): array
    {
        return [
            'product_id' => $map->product_id,
            'external_product_id' => $map->external_product_id,
            'sync_status' => $map->sync_status,
            'rejection_reason' => $map->rejection_reason,
            'last_synced_at' => $map->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function log(SocialCommerceSyncLog $log): array
    {
        return [
            'id' => $log->id,
            'account_id' => $log->social_commerce_account_id,
            'sync_type' => $log->sync_type,
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
