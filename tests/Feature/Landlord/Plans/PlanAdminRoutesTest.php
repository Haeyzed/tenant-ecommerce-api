<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Plans\Models\Plan;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(PlatformAccessSeeder::class);
    $admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $admin->assignRole('super-admin');
    $this->auth = ['Authorization' => 'Bearer '.$admin->createToken('t', ['platform'])->plainTextToken];
});

it('builds a plan with prices, features and limits through the admin API', function (): void {
    $planId = $this->landlordJson('POST', '/api/admin/plans', ['name' => 'Starter', 'tagline' => 'Try it'], $this->auth)
        ->assertCreated()
        ->assertJsonPath('data.slug', 'starter')
        ->assertJsonCount(11, 'data.limits')
        ->json('data.id');

    $this->landlordJson('POST', "/api/admin/plans/{$planId}/prices", ['currency_code' => 'USD', 'billing_interval' => 'monthly', 'amount' => 9], $this->auth)
        ->assertCreated()->assertJsonPath('data.amount', '9.0000')->assertJsonPath('data.resolved_trial_days', 7);

    $this->landlordJson('POST', "/api/admin/plans/{$planId}/features", ['feature_key' => 'pos'], $this->auth)
        ->assertCreated()->assertJsonPath('data', ['pos']);
    $this->landlordJson('POST', "/api/admin/plans/{$planId}/features", ['feature_key' => 'teleport'], $this->auth)->assertStatus(422);

    $this->landlordJson('POST', "/api/admin/plans/{$planId}/limits", ['limit_key' => 'max_storage_mb', 'limit_value' => null], $this->auth)->assertStatus(422);
    $this->landlordJson('POST', "/api/admin/plans/{$planId}/limits", ['limit_key' => 'max_products', 'limit_value' => null], $this->auth)->assertOk();

    $this->landlordJson('GET', '/api/plans')->assertOk()->assertJsonPath('data.0.slug', 'starter');
    $this->landlordJson('GET', '/api/plans/starter')->assertOk()->assertJsonPath('data.features.module.0.key', 'pos');
});

it('rejects changing the amount of a price', function (): void {
    $this->seedPlans();
    $plan = Plan::query()->where('slug', 'basic')->firstOrFail();
    $price = $plan->prices()->firstOrFail();

    $this->landlordJson('PATCH', "/api/admin/plans/{$plan->id}/prices/{$price->id}", ['amount' => 1], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'price_immutable');
    $this->landlordJson('PATCH', "/api/admin/plans/{$plan->id}/prices/{$price->id}", ['trial_days' => 14], $this->auth)
        ->assertOk()->assertJsonPath('data.trial_days', 14);
});

it('does not show private or inactive plans publicly', function (): void {
    $this->seedPlans();
    Plan::query()->where('slug', 'premium')->update(['is_public' => false]);

    $this->landlordJson('GET', '/api/plans')->assertOk()->assertJsonCount(2, 'data');
    $this->landlordJson('GET', '/api/plans/premium')->assertNotFound();
});

it('grants, lists and removes tenant overrides', function (): void {
    $this->seedPlans();
    $tenant = $this->createTenant('a');
    $this->subscribe($tenant, 'basic');

    $this->landlordJson('POST', "/api/admin/tenants/{$tenant->id}/features", [
        'feature_key' => 'pos', 'effect' => 'grant', 'extra_amount' => 10, 'extra_currency_code' => 'USD', 'reason' => 'Add-on',
    ], $this->auth)->assertCreated()->assertJsonPath('data.in_force', true);

    $this->landlordJson('GET', "/api/admin/tenants/{$tenant->id}/modules", [], $this->auth)
        ->assertOk()->assertJsonFragment(['key' => 'pos', 'state' => 'available', 'source' => 'override']);

    $this->landlordJson('PATCH', "/api/admin/tenants/{$tenant->id}/limit-overrides", ['limit_key' => 'max_pos_registers', 'limit_value' => 2], $this->auth)
        ->assertOk()->assertJsonPath('data.limit_value', 2);

    $this->landlordJson('DELETE', "/api/admin/tenants/{$tenant->id}/features/pos", [], $this->auth)->assertOk();
    $this->landlordJson('GET', "/api/admin/tenants/{$tenant->id}/features", [], $this->auth)->assertOk()->assertJsonCount(0, 'data');
});

it('manages platform settings by group, requiring a reason where the registry says so', function (): void {
    $this->landlordJson('GET', '/api/admin/platform-settings/general', [], $this->auth)->assertOk()->assertJsonStructure(['data' => ['platform_name']]);

    $this->landlordJson('PATCH', '/api/admin/platform-settings/trials', ['values' => ['default_trial_days' => 14]], $this->auth)->assertStatus(422);
    $this->landlordJson('PATCH', '/api/admin/platform-settings/trials', ['values' => ['default_trial_days' => 14], 'reason' => 'Promo'], $this->auth)
        ->assertOk()->assertJsonPath('data.default_trial_days.value', 14);

    $this->landlordJson('GET', '/api/platform/config')->assertOk()->assertJsonPath('data.default_trial_days', 14);
});

it('creates module notices that block the module for tenants', function (): void {
    $this->seedPlans();
    $tenant = $this->createTenant('a');

    $this->landlordJson('POST', '/api/admin/module-notices', [
        'module_key' => 'pos', 'type' => 'maintenance', 'behavior' => 'read_only', 'title' => 'POS upgrade', 'message' => 'Back soon', 'starts_at' => now()->subMinute()->toIso8601String(),
    ], $this->auth)->assertCreated();

    $this->landlordJson('POST', '/api/admin/module-notices', ['module_key' => 'nope', 'type' => 'info', 'title' => 'x', 'message' => 'y', 'starts_at' => now()->toIso8601String()], $this->auth)
        ->assertStatus(422);

    $this->subscribe($tenant, 'basic');
    $this->tenantJson('GET', '/api/module-notices')->assertOk()->assertJsonPath('data.0.title', 'POS upgrade');
});
