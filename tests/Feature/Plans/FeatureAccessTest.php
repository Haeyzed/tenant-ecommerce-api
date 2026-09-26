<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Enums\OverrideEffect;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\TenantModule;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Services\PlanService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->features = app(FeatureAccessService::class);
});

it('treats core as always enabled and everything else unavailable without a plan', function (): void {
    expect($this->features->state($this->tenant, 'core'))->toBe(ModuleState::Enabled)
        ->and($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Unavailable)
        ->and($this->features->isEntitled($this->tenant, 'pos'))->toBeFalse();
});

it('makes plan modules available (manual) or enabled (auto)', function (): void {
    $this->subscribe($this->tenant, 'standard');

    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Available)
        ->and($this->features->state($this->tenant, 'expenses'))->toBe(ModuleState::Enabled)
        ->and($this->features->state($this->tenant, 'hr'))->toBe(ModuleState::Unavailable)
        ->and($this->features->tenantCanAccess($this->tenant, 'pos'))->toBeFalse()
        ->and($this->features->tenantCanAccess($this->tenant, 'expenses'))->toBeTrue();
});

it('enables a module on an explicit activation row and disables it on a disabled row', function (): void {
    $this->subscribe($this->tenant, 'standard');

    TenantModule::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'pos', 'status' => 'enabled']);
    $this->features->flush($this->tenant);
    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Enabled);

    TenantModule::query()->where('module_key', 'pos')->update(['status' => 'disabled']);
    $this->features->flush($this->tenant);
    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Disabled)
        ->and($this->features->canRead($this->tenant, 'pos'))->toBeTrue();
});

it('locks a used module when the entitlement is lost, and restores the choice when regained', function (): void {
    $this->subscribe($this->tenant, 'standard');
    TenantModule::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'pos', 'status' => 'enabled']);

    $this->features->setOverride($this->tenant, 'pos', OverrideEffect::Revoke);
    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Locked)
        ->and($this->features->entitledByPlans('pos'))->toBe(['standard', 'premium']);

    $this->features->removeOverride($this->tenant, 'pos');
    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Enabled);
});

it('grants a module the plan lacks and suspends one whatever the entitlement', function (): void {
    $this->subscribe($this->tenant, 'basic');

    $this->features->setOverride($this->tenant, 'hr', OverrideEffect::Grant, ['reason' => 'Negotiated add-on']);
    expect($this->features->state($this->tenant, 'hr'))->toBe(ModuleState::Available)
        ->and(collect($this->features->listEffectiveModules($this->tenant))->firstWhere('key', 'hr')['source'])->toBe('override');

    $this->features->setOverride($this->tenant, 'expenses', OverrideEffect::Suspend, ['reason' => 'Legal hold']);
    expect($this->features->state($this->tenant, 'expenses'))->toBe(ModuleState::Suspended)
        ->and($this->features->canRead($this->tenant, 'expenses'))->toBeFalse();
});

it('reports a module disabled while a required module is not enabled', function (): void {
    $this->subscribe($this->tenant, 'premium');
    TenantModule::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'hr_payroll', 'status' => 'enabled']);

    expect($this->features->state($this->tenant, 'hr_payroll'))->toBe(ModuleState::Disabled)
        ->and($this->features->reason($this->tenant, 'hr_payroll'))->toBe('requirement_not_enabled');

    TenantModule::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'hr', 'status' => 'enabled']);
    $this->features->flush($this->tenant);

    expect($this->features->state($this->tenant, 'hr_payroll'))->toBe(ModuleState::Enabled);
});

it('applies a time-boxed override exactly at its boundaries without a flush', function (): void {
    $this->subscribe($this->tenant, 'basic');
    $this->travelTo(now()->startOfMinute());

    $this->features->setOverride($this->tenant, 'hr', OverrideEffect::Grant, [
        'starts_at' => now()->addMinutes(5),
        'expires_at' => now()->addMinutes(10),
    ]);

    expect($this->features->state($this->tenant, 'hr'))->toBe(ModuleState::Unavailable);

    $this->travel(5)->minutes();
    expect($this->features->state($this->tenant, 'hr'))->toBe(ModuleState::Available);

    $this->travel(5)->minutes();
    expect($this->features->state($this->tenant, 'hr'))->toBe(ModuleState::Unavailable);
});

it('reaches every tenant on a plan when the plan gains a feature', function (): void {
    $this->subscribe($this->tenant, 'basic');
    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Unavailable);

    app(PlanService::class)->attachFeatureToPlan(Plan::query()->where('slug', 'basic')->firstOrFail(), 'pos');

    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Available);
});

it('uses the latest subscription, keeping states for a cancelled one', function (): void {
    $this->subscribe($this->tenant, 'standard', SubscriptionStatus::Cancelled, ['ends_at' => now()->addDays(3)]);

    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Available);
});
