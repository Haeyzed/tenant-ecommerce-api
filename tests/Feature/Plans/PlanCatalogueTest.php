<?php

declare(strict_types=1);

use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Plans\Services\PlanService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seedPlans();
    $this->plans = app(PlanService::class);
});

it('seeds the three plans of the catalogue idempotently', function (): void {
    $this->seedPlans();

    // 3 plans × 7 currencies × 2 intervals.
    expect(Plan::query()->orderBy('sort_order')->pluck('slug')->all())->toBe(['basic', 'standard', 'premium'])
        ->and(PlanPrice::query()->count())->toBe(42)
        ->and(PlanPrice::query()->distinct()->orderBy('currency_code')->pluck('currency_code')->all())->toBe(['EUR', 'GBP', 'GHS', 'KES', 'NGN', 'USD', 'ZAR'])
        ->and(PlanLimit::query()->count())->toBe(33)
        ->and(PlanLimit::query()->whereNull('limit_value')->count())->toBe(1);
});

it('adds missing currency prices to existing plans without touching edited or retired ones', function (): void {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $ngn = fn (string $interval) => $standard->prices()->where('currency_code', 'NGN')->where('billing_interval', $interval);

    // The operator repriced NGN monthly, retired NGN yearly and never had GHS.
    $edited = $this->plans->addPrice($standard, 'NGN', 'monthly', '32000.00', 0);
    $ngn('yearly')->update(['is_active' => false]);
    $standard->prices()->where('currency_code', 'GHS')->delete();

    $this->seedPlans();

    expect($ngn('monthly')->where('is_active', true)->sole()->id)->toBe($edited->id)
        ->and($ngn('yearly')->where('is_active', true)->exists())->toBeFalse()
        ->and($standard->prices()->where('currency_code', 'GHS')->where('is_active', true)->pluck('amount', 'billing_interval')->map(fn ($a): string => (string) $a)->all())
        ->toEqualCanonicalizing(['monthly' => '480.0000', 'yearly' => '4800.0000']);
});

it('computes annual savings rounded down', function (string $slug, int $expected): void {
    $plan = Plan::query()->where('slug', $slug)->firstOrFail();

    expect($this->plans->annualSavingsPercent($plan, 'USD'))->toBe($expected);
})->with([['basic', 16], ['standard', 18], ['premium', 15]]);

it('resolves trial days: kill switch, then the price, then the platform default', function (): void {
    $basic = PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'basic'))->where('currency_code', 'USD')->where('billing_interval', 'monthly')->firstOrFail();
    $standard = PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'standard'))->where('currency_code', 'USD')->where('billing_interval', 'monthly')->firstOrFail();

    expect($this->plans->resolveTrialDays($basic))->toBe(7)
        ->and($this->plans->resolveTrialDays($standard))->toBe(0);

    app(PlatformSettingsService::class)->set('trials_enabled', false);

    expect($this->plans->resolveTrialDays($basic))->toBe(0);
});

it('keeps one active price per currency and interval, and prices immutable', function (): void {
    $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
    $old = $plan->prices()->where('currency_code', 'USD')->where('billing_interval', 'monthly')->firstOrFail();

    $new = $this->plans->addPrice($plan, 'usd', 'monthly', '25.00');

    expect($old->refresh()->is_active)->toBeFalse()
        ->and($new->is_active)->toBeTrue()
        ->and($new->currency_code)->toBe('USD');

    expect(fn () => $this->plans->updatePrice($new, ['amount' => '30.00']))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('price_immutable'));

    $this->plans->updatePrice($old, ['is_active' => true]);
    expect($new->refresh()->is_active)->toBeFalse();
});

it('falls back to USD when the tenant currency has no price', function (): void {
    // JPY is not in the seeded catalogue.
    $tenant = $this->createTenant('a', ['default_currency' => 'JPY']);
    $plan = Plan::query()->where('slug', 'standard')->firstOrFail();

    expect($this->plans->getPriceForTenant($plan, $tenant, 'yearly')->currency_code)->toBe('USD');

    $jpy = $this->plans->addPrice($plan, 'JPY', 'yearly', '60000.00');
    expect($this->plans->getPriceForTenant($plan, $tenant, 'yearly')->id)->toBe($jpy->id);
});

it('lists public plans with resolved trials, savings and grouped features', function (): void {
    $plans = $this->plans->listPublicPlans('USD');

    $basic = $plans->firstWhere('slug', 'basic');
    $yearly = collect($basic['prices'])->firstWhere('billing_interval', 'yearly');

    expect($plans)->toHaveCount(3)
        ->and($yearly['trial_days'])->toBe(7)
        ->and($yearly['annual_savings_percent'])->toBe(16)
        ->and($basic['limits']['max_users'])->toBe(2)
        ->and($basic['features'])->not->toBeEmpty();
});

it('keeps a single recommended plan', function (): void {
    [$basic, $standard] = Plan::query()->orderBy('sort_order')->take(2)->get()->all();

    $this->plans->updatePlan($basic, ['is_recommended' => true]);
    $this->plans->updatePlan($standard, ['is_recommended' => true]);

    expect(Plan::query()->where('is_recommended', true)->pluck('slug')->all())->toBe(['standard']);
});

it('rejects unlimited values where the registry forbids them', function (): void {
    $plan = Plan::query()->where('slug', 'basic')->firstOrFail();

    expect(fn () => $this->plans->setPlanLimit($plan, 'max_storage_mb', null))->toThrow(ValidationException::class);

    $this->plans->setPlanLimit($plan, 'max_products', null);
    expect(PlanLimit::query()->where('plan_id', $plan->id)->where('limit_key', 'max_products')->value('limit_value'))->toBeNull();
});

it('resolves limits: override first, plan next, missing row fails closed', function (): void {
    $tenant = $this->createTenant('a');
    $limits = app(PlanLimitService::class);

    expect($limits->getLimit($tenant, 'max_users'))->toBe(0);

    $this->subscribe($tenant, 'basic');
    $limits->flush($tenant);
    expect($limits->getLimit($tenant, 'max_users'))->toBe(2)
        ->and($limits->isWithinLimit($tenant, 'max_users', 1))->toBeTrue()
        ->and($limits->isWithinLimit($tenant, 'max_users', 2))->toBeFalse();

    $limits->setLimitOverride($tenant, 'max_users', null, ['reason' => 'Goodwill', 'billed' => false]);
    expect($limits->getLimit($tenant, 'max_users'))->toBeNull()
        ->and($limits->isWithinLimit($tenant, 'max_users', 10_000))->toBeTrue();

    $limits->removeLimitOverride($tenant, 'max_users');
    expect($limits->getLimit($tenant, 'max_users'))->toBe(2);
});

it('summarises usage in the tenant context', function (): void {
    $tenant = $this->createTenant('a');
    $this->subscribe($tenant, 'basic');

    $summary = app(PlanLimitService::class)->getUsageSummary($tenant);

    expect($summary['max_users'])->toBe(['used' => 0, 'limit' => 2, 'label' => 'Staff accounts'])
        ->and($summary['max_custom_domains']['used'])->toBe(0)
        ->and($summary)->not->toHaveKey('max_api_requests_per_minute');
});
