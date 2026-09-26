<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Users\Models\User;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);
    DB::connection('landlord')->table('states')->insert(['id' => 10, 'country_id' => 1, 'name' => 'Lagos', 'country_code' => 'NG', 'state_code' => 'LA']);
    DB::connection('landlord')->table('currencies')->insert(['country_id' => 1, 'name' => 'Naira', 'code' => 'NGN', 'symbol' => 'N', 'symbol_native' => 'N']);
});

it('serves World lookups publicly on the landlord domain', function (): void {
    $this->landlordJson('GET', '/api/lookups/countries')
        ->assertOk()->assertJsonPath('data.0', ['value' => 1, 'label' => 'Nigeria', 'meta' => ['iso2' => 'NG', 'iso3' => 'NGA', 'phone_code' => '234', 'emoji' => '-']]);
    $this->landlordJson('GET', '/api/lookups/currencies')->assertOk()->assertJsonPath('data.0.value', 'NGN');
    $this->landlordJson('GET', '/api/lookups/plans')->assertNotFound();
});

it('serves landlord admin lookups to any platform user without a permission', function (): void {
    $this->seed(PlatformAccessSeeder::class);
    $user = PlatformUser::query()->create(['name' => 'S', 'email' => 's@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $user->assignRole('content-editor');
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t', ['platform'])->plainTextToken];

    $this->landlordJson('GET', '/api/admin/lookups/limit-keys', [], $auth)->assertOk()->assertJsonFragment(['value' => 'max_users']);
    $this->landlordJson('GET', '/api/admin/lookups/tenant-statuses', [], $auth)->assertOk()->assertJsonCount(7, 'data');
    $this->landlordJson('GET', '/api/admin/lookups/limit-keys')->assertUnauthorized();
});

it('filters states by a required country on the tenant storefront', function (): void {
    $tenant = $this->createTenant('a');
    $this->subscribe($tenant, 'basic');

    $this->tenantJson('GET', '/api/lookups/states?country_id=1')->assertOk()->assertJsonPath('data.0.label', 'Lagos');
    $this->tenantJson('GET', '/api/lookups/states')->assertStatus(422);
});

it('serves tenant admin lookups to any staff user', function (): void {
    $tenant = $this->createTenant('a');
    $this->subscribe($tenant, 'basic');
    tenancy()->initialize($tenant);
    $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);

    $this->tenantJson('GET', '/api/admin/lookups/roles', [], ['Authorization' => 'Bearer '.$clerk->createToken('t', ['staff'])->plainTextToken])
        ->assertOk()->assertJsonFragment(['label' => 'owner']);
});
