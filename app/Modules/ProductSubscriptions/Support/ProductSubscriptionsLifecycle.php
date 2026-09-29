<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Support;

use App\Contracts\ModuleLifecycle;
use App\Modules\ProductSubscriptions\Models\CustomerSubscription;

/**
 * The product-subscriptions module's lifecycle (spec §11.5): it cannot be
 * disabled while any subscription still renews (active, paused or retrying
 * a failed payment). Staff cancel them first; each customer is notified.
 */
final class ProductSubscriptionsLifecycle implements ModuleLifecycle
{
    public function seedDefaults(): void {}

    public function disableBlockers(): array
    {
        $live = CustomerSubscription::query()->whereIn('status', CustomerSubscription::LIVE)->count();

        return $live === 0 ? [] : ["{$live} customer ".($live === 1 ? 'subscription is' : 'subscriptions are').' still running. Cancel them first.'];
    }

    public function onEnabled(): void {}

    public function onDisabled(): void {}
}
