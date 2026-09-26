<?php

declare(strict_types=1);

namespace App\Modules\Plans\Services;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Plans\Support\PlanCatalogueVersion;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The plan catalogue (spec §11.6, §11.12). Prices, trials, features and
 * limits are data: no code branches on a plan name, slug or amount.
 */
final class PlanService
{
    public const array INTERVALS = ['monthly', 'yearly'];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PlatformSettingsService $settings,
        private readonly PlanLimitService $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPlan(array $data): Plan
    {
        return DB::connection('landlord')->transaction(function () use ($data): Plan {
            $data['slug'] ??= Str::slug((string) $data['name']);

            if (($data['is_recommended'] ?? false) === true) {
                $this->clearRecommended();
            }

            /** @var Plan $plan */
            $plan = Plan::query()->create($data);

            foreach ((array) config('limits') as $key => $definition) {
                PlanLimit::query()->create(['plan_id' => $plan->id, 'limit_key' => $key, 'limit_value' => $definition['default']]);
            }

            return $plan->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePlan(Plan $plan, array $data): Plan
    {
        return DB::connection('landlord')->transaction(function () use ($plan, $data): Plan {
            $plan->fill($data);

            if ($plan->is_active && $plan->isDirty('is_active')) {
                $this->assertLimitsComplete($plan);
            }

            if ($plan->is_recommended && ($plan->isDirty('is_recommended') || $plan->isDirty('is_active') || $plan->isDirty('is_public'))) {
                if (! $plan->is_active || ! $plan->is_public) {
                    throw ValidationException::withMessages(['is_recommended' => ['Only an active public plan can be recommended.']]);
                }

                $this->clearRecommended($plan->id);
            }

            $plan->save();

            return $plan;
        });
    }

    /**
     * No new subscriptions; existing ones continue (spec §11.6).
     */
    public function deactivatePlan(Plan $plan): void
    {
        $plan->forceFill(['is_active' => false, 'is_recommended' => false])->save();
    }

    public function addPrice(
        Plan $plan,
        string $currency,
        string $interval,
        string|float $amount,
        ?int $trialDays = null,
        bool $trialRequiresPaymentMethod = false,
    ): PlanPrice {
        validator(compact('currency', 'interval', 'amount', 'trialDays'), [
            'currency' => ['required', 'string', 'size:3'],
            'interval' => ['required', 'in:'.implode(',', self::INTERVALS)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'trialDays' => ['nullable', 'integer', 'min:0', 'max:365'],
        ])->validate();

        return DB::connection('landlord')->transaction(function () use ($plan, $currency, $interval, $amount, $trialDays, $trialRequiresPaymentMethod): PlanPrice {
            // Serialises concurrent price changes of one plan.
            Plan::query()->whereKey($plan->id)->lockForUpdate()->first();

            $currency = strtoupper($currency);

            PlanPrice::query()
                ->where('plan_id', $plan->id)
                ->where('currency_code', $currency)
                ->where('billing_interval', $interval)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            /** @var PlanPrice $price */
            $price = PlanPrice::query()->create([
                'plan_id' => $plan->id,
                'currency_code' => $currency,
                'billing_interval' => $interval,
                'amount' => (string) $amount,
                'trial_days' => $trialDays,
                'trial_requires_payment_method' => $trialRequiresPaymentMethod,
                'is_active' => true,
            ]);

            return $price;
        });
    }

    /**
     * Only trial_days, trial_requires_payment_method and is_active change;
     * the amount, currency and interval are immutable (spec §11.6).
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePrice(PlanPrice $price, array $data): PlanPrice
    {
        $immutable = array_intersect(array_keys($data), ['amount', 'currency_code', 'billing_interval', 'plan_id']);

        if ($immutable !== []) {
            throw ApiException::unprocessable('price_immutable', 'Create a new price instead of changing the amount, currency or interval.', [
                'fields' => array_values($immutable),
            ]);
        }

        return DB::connection('landlord')->transaction(function () use ($price, $data): PlanPrice {
            Plan::query()->whereKey($price->plan_id)->lockForUpdate()->first();

            $price->fill(array_intersect_key($data, array_flip(['trial_days', 'trial_requires_payment_method', 'is_active'])));

            if ($price->is_active && $price->isDirty('is_active')) {
                PlanPrice::query()
                    ->where('plan_id', $price->plan_id)
                    ->where('currency_code', $price->currency_code)
                    ->where('billing_interval', $price->billing_interval)
                    ->where('id', '!=', $price->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            $price->save();

            return $price;
        });
    }

    public function isPriceInUse(PlanPrice $price): bool
    {
        return Subscription::query()->where('plan_price_id', $price->id)->exists();
    }

    public function attachFeatureToPlan(Plan $plan, string $featureKey): void
    {
        if (! $this->registry->has($featureKey)) {
            throw ValidationException::withMessages(['feature_key' => ["Unknown feature key [{$featureKey}]."]]);
        }

        PlanFeature::query()->firstOrCreate(['plan_id' => $plan->id, 'feature_key' => $featureKey]);
        PlanCatalogueVersion::bump();
    }

    public function detachFeatureFromPlan(Plan $plan, string $featureKey): void
    {
        PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', $featureKey)->delete();
        PlanCatalogueVersion::bump();
    }

    public function setPlanLimit(Plan $plan, string $limitKey, ?int $value): PlanLimit
    {
        if (! array_key_exists($limitKey, (array) config('limits'))) {
            throw ValidationException::withMessages(['limit_key' => ["Unknown limit key [{$limitKey}]."]]);
        }

        $this->limits->assertValue($limitKey, $value);

        /** @var PlanLimit $limit */
        $limit = PlanLimit::query()->updateOrCreate(['plan_id' => $plan->id, 'limit_key' => $limitKey], ['limit_value' => $value]);
        PlanCatalogueVersion::bump();

        return $limit;
    }

    /**
     * The tenant's currency and interval, then USD, else not purchasable
     * (spec §11.6 price resolution).
     */
    public function getPriceForTenant(Plan $plan, Tenant $tenant, string $interval): PlanPrice
    {
        $currencies = array_unique([strtoupper($tenant->default_currency), 'USD']);

        foreach ($currencies as $currency) {
            $price = PlanPrice::query()
                ->where('plan_id', $plan->id)
                ->where('currency_code', $currency)
                ->where('billing_interval', $interval)
                ->where('is_active', true)
                ->first();

            if ($price !== null) {
                return $price;
            }
        }

        throw ApiException::unprocessable('plan_not_purchasable', 'This plan cannot be bought with that billing interval.', [
            'plan' => $plan->slug,
            'interval' => $interval,
        ]);
    }

    /**
     * The only place a trial length is computed (spec §11.6).
     */
    public function resolveTrialDays(PlanPrice $price): int
    {
        if (! (bool) $this->settings->get('trials_enabled', true)) {
            return 0;
        }

        if ($price->trial_days !== null) {
            return $price->trial_days;
        }

        return (int) $this->settings->get('default_trial_days', 7);
    }

    /**
     * floor((monthly × 12 − yearly) ÷ (monthly × 12) × 100), never
     * overstated; null without both active prices or without a saving.
     */
    public function annualSavingsPercent(Plan $plan, string $currency): ?int
    {
        $prices = PlanPrice::query()
            ->where('plan_id', $plan->id)
            ->where('currency_code', strtoupper($currency))
            ->where('is_active', true)
            ->pluck('amount', 'billing_interval');

        return self::savings($prices->get('monthly'), $prices->get('yearly'));
    }

    public static function savings(?string $monthly, ?string $yearly): ?int
    {
        if ($monthly === null || $yearly === null || bccomp($monthly, '0', 4) <= 0) {
            return null;
        }

        $annualised = bcmul($monthly, '12', 4);
        $saving = bcsub($annualised, $yearly, 4);

        if (bccomp($saving, '0', 4) <= 0) {
            return null;
        }

        // Scale 0 truncates, which is floor for a positive value.
        return (int) bcdiv(bcmul($saving, '100', 4), $annualised, 0);
    }

    /**
     * Active public plans with their active prices in the currency (USD per
     * interval where the currency has none), resolved trial days, savings,
     * features grouped by class and limits.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listPublicPlans(?string $currency = null): Collection
    {
        $currency = strtoupper($currency ?? (string) $this->settings->get('default_currency', 'USD'));

        return Plan::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->with(['prices' => static fn ($q) => $q->where('is_active', true), 'features', 'limits'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan): array => $this->presentPublic($plan, $currency))
            ->filter(static fn (array $plan): bool => $plan['prices'] !== [])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPublic(Plan $plan, string $currency): array
    {
        $plan->loadMissing(['prices' => static fn ($q) => $q->where('is_active', true), 'features', 'limits']);

        $prices = [];

        foreach (self::INTERVALS as $interval) {
            $price = $plan->prices->first(static fn (PlanPrice $p): bool => $p->currency_code === $currency && $p->billing_interval === $interval)
                ?? $plan->prices->first(static fn (PlanPrice $p): bool => $p->currency_code === 'USD' && $p->billing_interval === $interval);

            if ($price !== null) {
                $prices[] = [
                    'id' => $price->id,
                    'currency_code' => $price->currency_code,
                    'billing_interval' => $price->billing_interval,
                    'amount' => $price->amount,
                    'trial_days' => $this->resolveTrialDays($price),
                    'trial_requires_payment_method' => $price->trial_requires_payment_method,
                ];
            }
        }

        $monthly = collect($prices)->firstWhere('billing_interval', 'monthly');
        $yearly = collect($prices)->firstWhere('billing_interval', 'yearly');
        $sameCurrency = $monthly !== null && $yearly !== null && $monthly['currency_code'] === $yearly['currency_code'];

        foreach ($prices as $i => $price) {
            $prices[$i]['annual_savings_percent'] = $price['billing_interval'] === 'yearly' && $sameCurrency
                ? self::savings($monthly['amount'], $yearly['amount'])
                : null;
        }

        $features = [];

        foreach ($plan->features as $feature) {
            if ($this->registry->has($feature->feature_key)) {
                $definition = $this->registry->get($feature->feature_key);
                $features[$definition->class][] = ['key' => $feature->feature_key, 'name' => $definition->name];
            }
        }

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'tagline' => $plan->tagline,
            'is_recommended' => $plan->is_recommended,
            'marketing_badge' => $plan->marketing_badge,
            'sort_order' => $plan->sort_order,
            'prices' => $prices,
            'features' => $features,
            'limits' => $plan->limits->mapWithKeys(static fn (PlanLimit $l): array => [$l->limit_key => $l->limit_value])->all(),
        ];
    }

    private function clearRecommended(?int $except = null): void
    {
        Plan::query()
            ->where('is_recommended', true)
            ->when($except !== null, static fn ($q) => $q->where('id', '!=', $except))
            ->update(['is_recommended' => false]);
    }

    private function assertLimitsComplete(Plan $plan): void
    {
        $present = PlanLimit::query()->where('plan_id', $plan->id)->pluck('limit_key')->all();
        $missing = array_values(array_diff(array_keys((array) config('limits')), $present));

        if ($missing !== []) {
            throw ApiException::unprocessable('plan_limits_incomplete', 'Set every limit before activating the plan.', ['missing' => $missing]);
        }
    }
}
