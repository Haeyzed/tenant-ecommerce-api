<?php

declare(strict_types=1);

use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Enums\OverrideEffect;
use App\Modules\Plans\Models\TenantModule;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Services\ModuleActivationService;
use App\Modules\Users\Models\User;
use App\Shared\Activity\LandlordActivity;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->activation = app(ModuleActivationService::class);
    $this->features = app(FeatureAccessService::class);

    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
});

it('enables and disables a manual module, recording who did it', function (): void {
    $this->subscribe($this->tenant, 'standard');

    $this->activation->enable($this->tenant, 'pos', $this->owner);

    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Enabled);

    $row = TenantModule::query()->where('tenant_id', $this->tenant->id)->where('module_key', 'pos')->firstOrFail();
    expect($row->changed_by_email)->toBe('owner@a.test')->and($row->enabled_at)->not->toBeNull();

    $this->activation->disable($this->tenant, 'pos', $this->owner);

    expect($this->features->state($this->tenant, 'pos'))->toBe(ModuleState::Disabled)
        ->and(LandlordActivity::query()->where('log_name', 'module_activation')->count())->toBe(2);

    $periods = collect($this->features->listEffectiveModules($this->tenant))->firstWhere('key', 'pos')['inactive_periods'];
    expect($periods)->toHaveCount(1)->and($periods[0]['to'])->toBeNull();
});

it('refuses to enable a module whose requirement is not enabled', function (): void {
    $this->subscribe($this->tenant, 'premium');

    expect(fn () => $this->activation->enable($this->tenant, 'hr_payroll', $this->owner))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('module_requirements_not_enabled')
            ->and($e->details['requires'])->toBe(['hr']));
});

it('refuses to disable a module an enabled module requires', function (): void {
    $this->subscribe($this->tenant, 'premium');
    $this->activation->enable($this->tenant, 'hr', $this->owner);
    $this->activation->enable($this->tenant, 'hr_payroll', $this->owner);

    expect(fn () => $this->activation->disable($this->tenant, 'hr', $this->owner))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('module_has_dependents')
            ->and($e->details['dependents'])->toBe(['hr_payroll']));
});

it('refuses activation changes for modules the tenant is not entitled to', function (): void {
    $this->subscribe($this->tenant, 'basic');

    expect(fn () => $this->activation->enable($this->tenant, 'pos', $this->owner))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('feature_unavailable')
            ->and($e->status)->toBe(403)
            ->and($e->details['entitled_by_plans'])->toBe(['standard', 'premium']));

    $this->features->setOverride($this->tenant, 'expenses', OverrideEffect::Suspend);

    expect(fn () => $this->activation->disable($this->tenant, 'expenses', $this->owner))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('module_suspended'));
});

it('inserts enabled rows for entitled auto modules once', function (): void {
    $this->subscribe($this->tenant, 'standard');

    $enabled = $this->activation->syncAutoModules($this->tenant);

    expect($enabled)->toEqualCanonicalizing(['expenses', 'content_marketing', 'back_in_stock_alerts', 'advanced_reporting', 'custom_email'])
        ->and($this->activation->syncAutoModules($this->tenant))->toBe([]);
});

it('syncs auto modules when a grant override is created', function (): void {
    $this->subscribe($this->tenant, 'basic');

    $this->features->setOverride($this->tenant, 'advanced_reporting', OverrideEffect::Grant);

    expect(TenantModule::query()->where('tenant_id', $this->tenant->id)->where('module_key', 'advanced_reporting')->value('status'))
        ->toBe('enabled');
});
