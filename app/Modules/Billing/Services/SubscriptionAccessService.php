<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * The access restriction a tenant's subscription imposes (spec §6.5,
 * UD-02): none, read_only or blocked. Cached briefly in the landlord store;
 * billing flushes it whenever a subscription changes.
 */
final class SubscriptionAccessService
{
    public const string NONE = 'none';

    public const string READ_ONLY = 'read_only';

    public const string BLOCKED = 'blocked';

    public function __construct(private readonly PlatformSettingsService $settings) {}

    public function restriction(Tenant $tenant): string
    {
        return Cache::store('landlord')->remember(
            $this->cacheKey($tenant),
            60,
            fn (): string => $this->compute($tenant),
        );
    }

    public function flush(Tenant $tenant): void
    {
        Cache::store('landlord')->forget($this->cacheKey($tenant));
    }

    private function compute(Tenant $tenant): string
    {
        $subscription = Subscription::governing((string) $tenant->getTenantKey());

        if ($subscription === null) {
            return self::BLOCKED;
        }

        return match ($subscription->status) {
            SubscriptionStatus::Active, SubscriptionStatus::Trialing => self::NONE,
            SubscriptionStatus::Cancelled => $subscription->ends_at === null || $subscription->ends_at->isFuture()
                ? self::NONE
                : self::BLOCKED,
            SubscriptionStatus::PastDue => $this->pastDue($subscription),
            // An incomplete subscription never reaches an active tenant.
            SubscriptionStatus::Incomplete => self::BLOCKED,
        };
    }

    private function pastDue(Subscription $subscription): string
    {
        $graceDays = (int) $this->settings->get('past_due_grace_days', 7);
        $since = $subscription->past_due_at ?? $subscription->updated_at;

        if ($since !== null && $since->copy()->addDays($graceDays)->isFuture()) {
            return self::NONE;
        }

        return (string) $this->settings->get('past_due_restriction', self::READ_ONLY);
    }

    private function cacheKey(Tenant $tenant): string
    {
        return 'subscription:access:'.$tenant->getTenantKey();
    }
}
