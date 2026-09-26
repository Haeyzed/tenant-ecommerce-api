<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\Landlord\PlanCatalogueSeeder;

trait InteractsWithPlans
{
    protected function seedPlans(): void
    {
        $this->seed(PlanCatalogueSeeder::class);
    }

    /**
     * Puts the tenant on a seeded plan with a fresh subscription.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function subscribe(Tenant $tenant, string $planSlug, SubscriptionStatus $status = SubscriptionStatus::Active, array $attributes = []): Subscription
    {
        if (! Plan::query()->where('slug', $planSlug)->exists()) {
            $this->seedPlans();
        }

        $plan = Plan::query()->where('slug', $planSlug)->firstOrFail();
        $price = $plan->prices()->where('billing_interval', 'monthly')->where('is_active', true)->firstOrFail();

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->create(array_merge([
            'tenant_id' => $tenant->getTenantKey(),
            'plan_id' => $plan->id,
            'plan_price_id' => $price->id,
            'currency_code' => $price->currency_code,
            'billing_interval' => 'monthly',
            'gateway_mode' => 'test',
            'status' => $status,
            'trial_days' => 0,
            'starts_at' => now(),
            'renews_at' => now()->addMonth(),
        ], $attributes));

        return $subscription;
    }
}
