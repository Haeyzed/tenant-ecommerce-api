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

    expect(Plan::query()->orderBy('sort_order')->pluck('slug')->all())->toBe(['basic', 'standard', 'premium'])
        ->and(PlanPrice::query()->count())->toBe(6)
        ->and(PlanLimit::query()->count())->toBe(33)
        ->and(PlanLimit::query()->whereNull('limit_value')->count())->toBe(1);
});

it('computes annual savings rounded down', function (string $slug, int $expected): void {
    $plan = Plan::query()->where('slug', $slug)->firstOrFail();

    expect($this->plans->annualSavingsPercent($plan, 'USD'))->toBe($expected);
})->with([['basic', 16], ['standard', 18], ['premium', 15]]);

it('resolves trial days: kill switch, then the price, then the platform default', function (): void {
    $basic = PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'basic'))->where('billing_interval', 'monthly')->firstOrFail();
    $standard = PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'standard'))->where('billing_interval', 'monthly')->firstOrFail();

    expect($this->plans->resolveTrialDays($basic))->toBe(7)
        ->and($this->plans->resolveTrialDays($standard))->toBe(0);

    app(PlatformSettingsService::class)->set('trials_enabled', false);

    expect($this->plans->resolveTrialDays($basic))->toBe(0);
});

it('keeps one active price per currency and interval, and prices immutable', function (): void {
    $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
    $old = $plan->prices()->where('billing_interval', 'monthly')->firstOrFail();

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
    $tenant = $this->createTenant('a', ['default_currency' => 'NGN']);
    $plan = Plan::query()->where('slug', 'standard')->firstOrFail();

    expect($this->plans->getPriceForTenant($plan, $tenant, 'yearly')->currency_code)->toBe('USD');

    $ngn = $this->plans->addPrice($plan, 'NGN', 'yearly', '550000.00');
    expect($this->plans->getPriceForTenant($plan, $tenant, 'yearly')->id)->toBe($ngn->id);
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
