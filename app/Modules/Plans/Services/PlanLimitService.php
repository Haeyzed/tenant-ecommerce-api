<?php

declare(strict_types=1);

namespace App\Modules\Plans\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Models\TenantLimitOverride;
use App\Modules\Plans\Support\PlanCatalogueVersion;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Effective limits and usage (spec §11.8, §11.10, §11.12). The effective
 * values of every key are resolved together and cached in the landlord
 * store until the earliest override expiry, like module states.
 */
final class PlanLimitService
{
    private const int TTL = 600;

    public function __construct(
        private readonly FeatureAccessService $features,
        private readonly UsageCounterRegistry $counters,
    ) {}

    /**
     * null = unlimited. A missing plan row is a configuration error: it
     * returns 0 (fail closed) and is logged.
     */
    public function getLimit(Tenant $tenant, string $limitKey): ?int
    {
        $this->assertKnown($limitKey);

        $limits = $this->resolved($tenant)['limits'];

        if (! array_key_exists($limitKey, $limits)) {
            Log::error('Plan limit row missing; failing closed.', ['tenant_id' => $tenant->getTenantKey(), 'limit_key' => $limitKey]);

            return 0;
        }

        return $limits[$limitKey];
    }

    public function isWithinLimit(Tenant $tenant, string $limitKey, int $currentCount): bool
    {
        $limit = $this->getLimit($tenant, $limitKey);

        return $limit === null || $currentCount < $limit;
    }

    /**
     * @param  array{extra_amount?: mixed, extra_currency_code?: string|null, billed?: bool, expires_at?: mixed, reason?: string|null}  $options
     */
    public function setLimitOverride(Tenant $tenant, string $limitKey, ?int $value, array $options = [], ?PlatformUser $by = null): TenantLimitOverride
    {
        $this->assertKnown($limitKey);
        $this->assertValue($limitKey, $value);

        if (isset($options['extra_amount']) && blank($options['extra_currency_code'] ?? null)) {
            throw ValidationException::withMessages(['extra_currency_code' => ['Required when an extra amount is set.']]);
        }

        /** @var TenantLimitOverride $override */
        $override = TenantLimitOverride::query()->updateOrCreate(
            ['tenant_id' => $tenant->getTenantKey(), 'limit_key' => $limitKey],
            [
                'limit_value' => $value,
                'extra_amount' => $options['extra_amount'] ?? null,
                'extra_currency_code' => $options['extra_currency_code'] ?? null,
                'billed' => $options['billed'] ?? true,
                'is_active' => true,
                'expires_at' => $options['expires_at'] ?? null,
                'reason' => $options['reason'] ?? null,
                'overridden_by' => $by?->getKey(),
            ],
        );

        $this->flush($tenant);

        ActivityRecorder::landlord('limits', "Limit override [{$limitKey}] set", $override, [
            'tenant_id' => $tenant->getTenantKey(),
            'limit_value' => $value,
            'reason' => $options['reason'] ?? null,
        ], $by);

        return $override;
    }

    public function removeLimitOverride(Tenant $tenant, string $limitKey, ?PlatformUser $by = null): void
    {
        TenantLimitOverride::query()->where('tenant_id', $tenant->getTenantKey())->where('limit_key', $limitKey)->delete();

        $this->flush($tenant);

        ActivityRecorder::landlord('limits', "Limit override [{$limitKey}] removed", $tenant, ['tenant_id' => $tenant->getTenantKey()], $by);
    }

    /**
     * Usage and limit of every count and storage key. Usage is counted in
     * the tenant context; a key whose counting module does not exist yet
     * reports used = null.
     *
     * @return array<string, array{used: int|null, limit: int|null, label: string}>
     */
    public function getUsageSummary(Tenant $tenant): array
    {
        $summary = [];
        $count = function () use ($tenant, &$summary): void {
            foreach ((array) config('limits') as $key => $definition) {
                if ($definition['kind'] === 'rate') {
                    continue;
                }

                $summary[$key] = [
                    'used' => $this->counters->has($key) ? $this->counters->count($key) : null,
                    'limit' => $this->getLimit($tenant, $key),
                    'label' => (string) $definition['label'],
                ];
            }
        };

        if (tenant()?->getTenantKey() === $tenant->getTenantKey()) {
            $count();
        } else {
            $tenant->run($count);
        }

        return $summary;
    }

    public function flush(Tenant $tenant): void
    {
        Cache::store('landlord')->forget($this->cacheKey($tenant));
    }

    public function assertValue(string $limitKey, ?int $value): void
    {
        if ($value === null && ! config("limits.{$limitKey}.unlimited_allowed")) {
            throw ValidationException::withMessages(['limit_value' => ["[{$limitKey}] cannot be unlimited."]]);
        }

        if ($value !== null && $value < 0) {
            throw ValidationException::withMessages(['limit_value' => ['The limit cannot be negative.']]);
        }
    }

    private function assertKnown(string $limitKey): void
    {
        if (! array_key_exists($limitKey, (array) config('limits'))) {
            throw ValidationException::withMessages(['limit_key' => ["Unknown limit key [{$limitKey}]."]]);
        }
    }

    /**
     * @return array{limits: array<string, int|null>, expires_at: int}
     */
    private function resolved(Tenant $tenant): array
    {
        $store = Cache::store('landlord');
        $key = $this->cacheKey($tenant);

        /** @var array{limits: array<string, int|null>, expires_at: int}|null $cached */
        $cached = $store->get($key);

        if (is_array($cached) && $cached['expires_at'] > now()->getTimestamp()) {
            return $cached;
        }

        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::TTL);
        $plan = $this->features->currentPlan($tenant);

        $limits = $plan === null ? [] : PlanLimit::query()
            ->where('plan_id', $plan->getKey())
            ->pluck('limit_value', 'limit_key')
            ->map(static fn (mixed $value): ?int => $value === null ? null : (int) $value)
            ->all();

        foreach (TenantLimitOverride::query()->where('tenant_id', $tenant->getTenantKey())->get() as $override) {
            if ($override->expires_at !== null && $override->expires_at->gt($now) && $override->expires_at->lt($expiresAt)) {
                $expiresAt = $override->expires_at->copy();
            }

            if ($override->isInForce()) {
                $limits[$override->limit_key] = $override->limit_value;
            }
        }

        $resolved = ['limits' => $limits, 'expires_at' => $expiresAt->getTimestamp()];
        $store->put($key, $resolved, max(1, $resolved['expires_at'] - now()->getTimestamp()));

        return $resolved;
    }

    private function cacheKey(Tenant $tenant): string
    {
        return 'limits:effective:'.PlanCatalogueVersion::current().':'.$tenant->getTenantKey();
    }
}
