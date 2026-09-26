<?php

declare(strict_types=1);

use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'standard');

    tenancy()->initialize($this->tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];
});

it('lists the default roles with the protected ones marked', function (): void {
    $this->tenantJson('GET', '/api/admin/roles', [], $this->auth)
        ->assertOk()
        ->assertJsonFragment(['name' => 'owner', 'protected' => true])
        ->assertJsonFragment(['name' => 'manager', 'protected' => false]);
});

it('creates, edits and deletes custom roles with canonical permissions only', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/roles', ['name' => 'Cashier', 'permissions' => ['settings.view']], $this->auth)
        ->assertCreated()->assertJsonPath('data.permissions', ['settings.view'])->json('data.id');

    $this->tenantJson('PUT', "/api/admin/roles/{$id}/permissions", ['permissions' => ['made.up']], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'permission_unknown');

    $this->tenantJson('PATCH', "/api/admin/roles/{$id}", ['name' => 'Front desk'], $this->auth)->assertOk()->assertJsonPath('data.name', 'Front desk');
    $this->tenantJson('DELETE', "/api/admin/roles/{$id}", [], $this->auth)->assertOk();
});

it('protects owner and admin and roles still in use', function (): void {
    $owner = Role::query()->where('name', 'owner')->firstOrFail();
    $manager = Role::query()->where('name', 'manager')->firstOrFail();
    User::query()->create(['name' => 'M', 'email' => 'm@a.test', 'password' => 'Secret123', 'is_active' => true])->assignRole('manager');

    $this->tenantJson('DELETE', "/api/admin/roles/{$owner->id}", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'role_protected');
    $this->tenantJson('PUT', "/api/admin/roles/{$owner->id}/permissions", ['permissions' => []], $this->auth)->assertStatus(422);
    $this->tenantJson('DELETE', "/api/admin/roles/{$manager->id}", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'role_in_use');
});

it('groups permissions by module, hiding modules the tenant cannot use', function (): void {
    $groups = collect($this->tenantJson('GET', '/api/admin/permissions', [], $this->auth)->assertOk()->json('data'));

    expect($groups->firstWhere('module', 'core')['permissions'])->toContain('roles.create')
        ->and($groups->firstWhere('module', 'hr'))->toBeNull();
});
