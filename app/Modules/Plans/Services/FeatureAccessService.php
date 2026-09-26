<?php

declare(strict_types=1);

namespace App\Modules\Plans\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Enums\OverrideEffect;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantFeature;
use App\Modules\Plans\Models\TenantModule;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Plans\Support\PlanCatalogueVersion;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Activity\LandlordActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Entitlement, activation and module state (spec §11.5, §11.9, §11.12).
 * The states of every key for a tenant are computed together and cached in
 * the landlord store; the entry expires no later than the earliest future
 * override boundary, so time-boxed overrides take effect without a job.
 */
final class FeatureAccessService
{
    private const int TTL = 600;

    public function __construct(private readonly ModuleRegistry $registry) {}

    public function state(Tenant $tenant, string $key): ModuleState
    {
        if ($key === 'core') {
            return ModuleState::Enabled;
        }

        return $this->states($tenant)[$key] ?? ModuleState::Unavailable;
    }

    /**
     * @return array<string, ModuleState>
     */
    public function states(Tenant $tenant): array
    {
        return array_map(
            static fn (string $state): ModuleState => ModuleState::from($state),
            $this->resolved($tenant)['states'],
        );
    }

    public function tenantCanAccess(Tenant $tenant, string $key): bool
    {
        return $this->state($tenant, $key) === ModuleState::Enabled;
    }

    /**
     * enabled, or disabled/locked with "read when inactive" (spec §11.12).
     */
    public function canRead(Tenant $tenant, string $key): bool
    {
        $state = $this->state($tenant, $key);

        return $state === ModuleState::Enabled
            || (in_array($state, [ModuleState::Disabled, ModuleState::Locked], true)
                && $this->registry->get($key)->readWhenInactive);
    }

    public function isEntitled(Tenant $tenant, string $key): bool
    {
        return (bool) ($this->resolved($tenant)['entitled'][$key] ?? false);
    }

    /**
     * Why an entitled, activated module is not enabled, when that is so.
     */
    public function reason(Tenant $tenant, string $key): ?string
    {
        return $this->resolved($tenant)['reasons'][$key] ?? null;
    }

    /**
     * Slugs of the active public plans that include the key; returned with
     * module_locked so the frontend can offer an upgrade (spec §11.5).
     *
     * @return list<string>
     */
    public function entitledByPlans(string $key): array
    {
        return Plan::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->whereIn('id', PlanFeature::query()->select('plan_id')->where('feature_key', $key))
            ->orderBy('sort_order')
            ->pluck('slug')
            ->all();
    }

    /**
     * Every registry key with its state, entitlement source, activation and
     * dependency information (spec §11.12).
     *
     * @return list<array<string, mixed>>
     */
    public function listEffectiveModules(Tenant $tenant): array
    {
        $resolved = $this->resolved($tenant);
        $rows = TenantModule::query()->where('tenant_id', $tenant->getTenantKey())->get()->keyBy('module_key');

        $result = [];

        foreach ($this->registry->all() as $key => $definition) {
            $state = ModuleState::from($resolved['states'][$key]);

            $result[] = [
                'key' => $key,
                'name' => $definition->name,
                'class' => $definition->class,
                'section' => $definition->section,
                'state' => $state->value,
                'entitled' => $resolved['entitled'][$key],
                'source' => $resolved['sources'][$key],
                'activation' => $rows->get($key)?->status,
                'activation_mode' => $definition->activation,
                'read_when_inactive' => $definition->readWhenInactive,
                'requires' => $definition->requires,
                'dependents' => $this->registry->dependents($key),
                'missing_requirements' => array_values(array_filter(
                    $definition->requires,
                    static fn (string $required): bool => $resolved['states'][$required] !== ModuleState::Enabled->value,
                )),
                'inactive_periods' => $this->inactivePeriods($tenant, $key),
            ];
        }

        return $result;
    }

    /**
     * Create or replace an override (spec §11.7, §11.12).
     *
     * @param  array{starts_at?: mixed, expires_at?: mixed, reason?: string|null, extra_amount?: mixed, extra_currency_code?: string|null, billed?: bool}  $options
     */
    public function setOverride(Tenant $tenant, string $key, OverrideEffect $effect, array $options = [], ?PlatformUser $by = null): TenantFeature
    {
        if (! $this->registry->has($key)) {
            throw ValidationException::withMessages(['feature_key' => ["Unknown feature key [{$key}]."]]);
        }

        if (isset($options['extra_amount']) && empty($options['extra_currency_code'])) {
            throw ValidationException::withMessages(['extra_currency_code' => ['Required when an extra amount is set.']]);
        }

        /** @var TenantFeature $override */
        $override = TenantFeature::query()->updateOrCreate(
            ['tenant_id' => $tenant->getTenantKey(), 'feature_key' => $key],
            [
                'effect' => $effect,
                'starts_at' => $options['starts_at'] ?? null,
                'expires_at' => $options['expires_at'] ?? null,
                'reason' => $options['reason'] ?? null,
                'extra_amount' => $options['extra_amount'] ?? null,
                'extra_currency_code' => $options['extra_currency_code'] ?? null,
                'billed' => $options['billed'] ?? true,
                'overridden_by' => $by?->getKey(),
            ],
        );

        $this->flush($tenant);

        ActivityRecorder::landlord('modules', "Feature override {$effect->value} [{$key}]", $override, [
            'tenant_id' => $tenant->getTenantKey(),
            'reason' => $options['reason'] ?? null,
        ], $by);

        if ($effect === OverrideEffect::Grant) {
            // Resolved lazily: the activation service depends on this one.
            app(ModuleActivationService::class)->syncAutoModules($tenant);
        }

        return $override;
    }

    public function removeOverride(Tenant $tenant, string $key, ?PlatformUser $by = null): void
    {
        TenantFeature::query()->where('tenant_id', $tenant->getTenantKey())->where('feature_key', $key)->delete();

        $this->flush($tenant);

        ActivityRecorder::landlord('modules', "Feature override removed [{$key}]", $tenant, ['tenant_id' => $tenant->getTenantKey()], $by);
    }

    public function flush(Tenant $tenant): void
    {
        Cache::store('landlord')->forget($this->cacheKey($tenant));
    }

    /**
     * The plan whose features and limits apply now: the current
     * subscription's plan (spec §14.2). A scheduled change applies only
     * when it takes effect.
     */
    public function currentPlan(Tenant $tenant): ?Plan
    {
        return Subscription::governing((string) $tenant->getTenantKey())?->plan;
    }

    /**
     * @return array{states: array<string, string>, entitled: array<string, bool>, sources: array<string, string>, reasons: array<string, string>}
     */
    private function resolved(Tenant $tenant): array
    {
        $key = $this->cacheKey($tenant);
        $store = Cache::store('landlord');

        /** @var array{states: array<string, string>, entitled: array<string, bool>, sources: array<string, string>, reasons: array<string, string>, expires_at: int}|null $cached */
        $cached = $store->get($key);

        if (is_array($cached) && $cached['expires_at'] > now()->getTimestamp()) {
            return $cached;
        }

        [$resolved, $expiresAt] = $this->compute($tenant);
        $resolved['expires_at'] = $expiresAt;

        $store->put($key, $resolved, max(1, $expiresAt - now()->getTimestamp()));

        return $resolved;
    }

    /**
     * @return array{0: array{states: array<string, string>, entitled: array<string, bool>, sources: array<string, string>, reasons: array<string, string>}, 1: int}
     */
    private function compute(Tenant $tenant): array
    {
        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::TTL);

        $overrides = TenantFeature::query()->where('tenant_id', $tenant->getTenantKey())->get();

        foreach ($overrides as $override) {
            foreach ([$override->starts_at, $override->expires_at] as $boundary) {
                if ($boundary instanceof Carbon && $boundary->gt($now) && $boundary->lt($expiresAt)) {
                    $expiresAt = $boundary->copy();
                }
            }
        }

        $inForce = $overrides->filter(static fn (TenantFeature $override): bool => $override->isInForce())->keyBy('feature_key');
        $plan = $this->currentPlan($tenant);
        $planKeys = $plan === null ? [] : PlanFeature::query()->where('plan_id', $plan->getKey())->pluck('feature_key')->all();
        $rows = TenantModule::query()->where('tenant_id', $tenant->getTenantKey())->get()->keyBy('module_key');

        $own = [];
        $entitled = [];
        $sources = [];

        foreach ($this->registry->all() as $key => $definition) {
            /** @var TenantFeature|null $override */
            $override = $inForce->get($key);
            $inPlan = in_array($key, $planKeys, true);

            $isEntitled = ($inPlan || $override?->effect === OverrideEffect::Grant)
                && $override?->effect !== OverrideEffect::Revoke;

            $entitled[$key] = $isEntitled;
            $sources[$key] = match (true) {
                $override !== null && $override->effect !== OverrideEffect::Suspend => 'override',
                $inPlan => 'plan',
                default => 'none',
            };

            /** @var TenantModule|null $row */
            $row = $rows->get($key);

            $own[$key] = match (true) {
                $override?->effect === OverrideEffect::Suspend => ModuleState::Suspended,
                ! $isEntitled && $row === null => ModuleState::Unavailable,
                ! $isEntitled => ModuleState::Locked,
                $row === null => $definition->isAuto() ? ModuleState::Enabled : ModuleState::Available,
                $row->status === TenantModule::ENABLED => ModuleState::Enabled,
                default => ModuleState::Disabled,
            };
        }

        $states = [];
        $reasons = [];

        foreach ($own as $key => $state) {
            if ($state === ModuleState::Enabled && ! $this->requirementsEnabled($key, $own)) {
                $states[$key] = ModuleState::Disabled->value;
                $reasons[$key] = 'requirement_not_enabled';

                continue;
            }

            $states[$key] = $state->value;
        }

        return [
            ['states' => $states, 'entitled' => $entitled, 'sources' => $sources, 'reasons' => $reasons],
            $expiresAt->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, ModuleState>  $own
     */
    private function requirementsEnabled(string $key, array $own): bool
    {
        foreach ($this->registry->requires($key) as $required) {
            if (($own[$required] ?? ModuleState::Unavailable) !== ModuleState::Enabled) {
                return false;
            }
        }

        return true;
    }

    /**
     * Periods in which the module was not enabled, from the activation
     * activity log (spec §11.5).
     *
     * @return list<array{from: string, to: string|null}>
     */
    private function inactivePeriods(Tenant $tenant, string $key): array
    {
        $events = LandlordActivity::query()
            ->where('log_name', 'module_activation')
            ->where('properties->tenant_id', $tenant->getTenantKey())
            ->where('properties->module_key', $key)
            ->orderBy('id')
            ->get(['properties', 'created_at']);

        $periods = [];
        $from = null;

        foreach ($events as $event) {
            $status = $event->properties['status'] ?? null;

            if ($status === TenantModule::DISABLED && $from === null) {
                $from = $event->created_at?->toIso8601String();
            }

            if ($status === TenantModule::ENABLED && $from !== null) {
                $periods[] = ['from' => $from, 'to' => $event->created_at?->toIso8601String()];
                $from = null;
            }
        }

        if ($from !== null) {
            $periods[] = ['from' => $from, 'to' => null];
        }

        return $periods;
    }

    private function cacheKey(Tenant $tenant): string
    {
        return 'modules:states:'.PlanCatalogueVersion::current().':'.$tenant->getTenantKey();
    }
}
