<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\UsageCounterRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * usage.limit:{key} on create and reactivate routes (spec §11.10). The
 * count and the action run under a per-tenant, per-key lock, so concurrent
 * creates cannot overshoot the limit.
 */
final readonly class EnsureWithinUsageLimit
{
    private const int APPROACHING_PERCENT = 80;

    public function __construct(
        private PlanLimitService $limits,
        private UsageCounterRegistry $counters,
        private NotificationDispatchService $notifications,
    ) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw ApiException::forbidden('forbidden', 'No tenant context.');
        }

        $lock = Cache::store('landlord')->lock('usage-limit:'.$tenant->getTenantKey().':'.$key, 30);

        return $lock->block(10, function () use ($request, $next, $tenant, $key): Response {
            $used = $this->counters->count($key);
            $limit = $this->limits->getLimit($tenant, $key);

            if ($limit !== null && $used >= $limit) {
                $this->notifyOnce($tenant, $key, 'subscription.plan_limit_reached', $used, $limit, 3600);

                throw ApiException::forbidden('limit_reached', 'Upgrade your plan to add more.', [
                    'limit' => $key,
                    'used' => $used,
                    'limit_value' => $limit,
                ]);
            }

            $response = $next($request);

            if ($limit !== null && $limit > 0 && $response->isSuccessful()
                && ($used + 1) * 100 >= $limit * self::APPROACHING_PERCENT) {
                $this->notifyOnce($tenant, $key, 'subscription.plan_limit_approaching', $used + 1, $limit, $this->secondsLeftInCycle($tenant));
            }

            return $response;
        });
    }

    /**
     * Once per limit per window: per billing cycle for "approaching", at
     * most hourly for "reached".
     */
    private function notifyOnce(Tenant $tenant, string $key, string $template, int $used, int $limit, int $ttl): void
    {
        $subscription = $this->subscription($tenant);
        $cycle = $subscription === null ? 'none' : $subscription->id.':'.($subscription->renews_at?->getTimestamp() ?? 0);

        if (! Cache::store('landlord')->add("limit-notice:{$template}:{$tenant->getTenantKey()}:{$key}:{$cycle}", true, max(60, $ttl))) {
            return;
        }

        $this->notifications->dispatch($template, $tenant, [
            'owner_name' => $tenant->owner_name,
            'limit_label' => (string) config("limits.{$key}.label"),
            'used' => $used,
            'limit_value' => $limit,
            'percent' => intdiv($used * 100, max(1, $limit)),
            'plan_name' => $subscription?->plan->name ?? '',
        ], data: ['limit' => $key]);
    }

    private function secondsLeftInCycle(Tenant $tenant): int
    {
        $renewsAt = $this->subscription($tenant)?->renews_at;

        return $renewsAt !== null && $renewsAt->isFuture() ? (int) now()->diffInSeconds($renewsAt) : 31 * 86400;
    }

    private function subscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('tenant_id', $tenant->getTenantKey())
            ->orderByDesc('id')
            ->first();
    }
}
