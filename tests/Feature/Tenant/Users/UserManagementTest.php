<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Modules\Users\Support\StaffAccessScope;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    $this->owner = User::query()->create(['name' => 'Olu Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
});

function staffUser(string $email, string $role): User
{
    tenancy()->initialize(test()->tenant);
    $user = User::query()->create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'Secret123', 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

it('creates staff with roles and invites those without a password', function (): void {
    $this->tenantJson('POST', '/api/admin/users', ['name' => 'X', 'email' => 'x@a.test', 'roles' => ['owner']], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'role_owner_not_assignable');
    $this->tenantJson('POST', '/api/admin/users', ['name' => 'Y', 'email' => 'y@a.test', 'roles' => ['wizard']], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('roles');

    $id = $this->tenantJson('POST', '/api/admin/users', ['name' => 'Mia', 'email' => 'Mia@A.test', 'roles' => ['manager']], $this->auth)
        ->assertCreated()->assertJsonPath('data.email', 'mia@a.test')->assertJsonPath('data.roles', ['manager'])->json('data.id');

    tenancy()->initialize($this->tenant);
    Notification::assertSentTo(User::query()->find($id), TemplatedNotification::class, fn ($n): bool => $n->key === 'staff.password_reset');

    // The basic plan allows two staff users (§11.8).
    $this->tenantJson('POST', '/api/admin/users', ['name' => 'Z', 'email' => 'z@a.test', 'roles' => ['staff']], $this->auth)
        ->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');

    $this->tenantJson('GET', "/api/admin/users/{$id}", [], $this->auth)->assertOk()
        ->assertJsonPath('data.is_owner', false)
        ->assertJsonPath('data.effective_permissions', fn (array $p): bool => in_array('customers.view', $p, true));
});

it('protects the owner and the acting user, and revokes sessions on deactivation', function (): void {
    $admin = staffUser('ada@a.test', 'admin');
    $clerk = staffUser('clerk@a.test', 'staff');
    $clerkToken = $clerk->createToken('t', ['staff'])->plainTextToken;
    $adminAuth = ['Authorization' => 'Bearer '.$admin->createToken('t', ['staff'])->plainTextToken];

    $this->tenantJson('POST', "/api/admin/users/{$this->owner->id}/deactivate", [], $adminAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'owner_protected');
    $this->tenantJson('DELETE', "/api/admin/users/{$this->owner->id}", [], $adminAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'owner_protected');
    $this->tenantJson('POST', "/api/admin/users/{$admin->id}/deactivate", [], $adminAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'cannot_modify_self');

    // Syncing roles never removes owner from the owner.
    $this->tenantJson('PUT', "/api/admin/users/{$this->owner->id}/roles", ['roles' => ['manager']], $adminAuth)->assertOk()
        ->assertJsonPath('data.roles', fn (array $r): bool => in_array('owner', $r, true) && in_array('manager', $r, true));

    $this->tenantJson('POST', "/api/admin/users/{$clerk->id}/deactivate", [], $adminAuth)->assertOk()->assertJsonPath('data.is_active', false);
    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$clerkToken])->assertUnauthorized();

    $this->tenantJson('PUT', "/api/admin/users/{$clerk->id}/permissions", ['permissions' => ['not.a.permission']], $adminAuth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'permission_unknown');
    $this->tenantJson('PUT', "/api/admin/users/{$clerk->id}/permissions", ['permissions' => ['customer-groups.view']], $adminAuth)
        ->assertOk()->assertJsonPath('data.direct_permissions', ['customer-groups.view']);
});

it('transfers ownership only by the owner with their password', function (): void {
    $admin = staffUser('ada@a.test', 'admin');
    $adminAuth = ['Authorization' => 'Bearer '.$admin->createToken('t', ['staff'])->plainTextToken];

    // admin lacks users.transfer-ownership (§12.3).
    $this->tenantJson('POST', "/api/admin/users/{$admin->id}/transfer-ownership", ['current_password' => 'Secret123'], $adminAuth)->assertForbidden();

    $this->tenantJson('POST', "/api/admin/users/{$admin->id}/transfer-ownership", ['current_password' => 'wrong'], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('current_password');

    $this->tenantJson('POST', "/api/admin/users/{$admin->id}/transfer-ownership", ['current_password' => 'Secret123'], $this->auth)
        ->assertOk()->assertJsonPath('data.is_owner', true);

    tenancy()->initialize($this->tenant);
    expect($this->owner->refresh()->getRoleNames()->all())->toBe(['admin'])
        ->and(User::query()->find($admin->id)->getRoleNames()->all())->toBe(['owner'])
        ->and(Tenant::query()->find($this->tenant->id)->email)->toBe('ada@a.test');

    Notification::assertSentTo([$admin, $this->owner], TemplatedNotification::class, fn ($n): bool => $n->key === 'staff.ownership_transferred');
});

it('narrows non-admin staff by the data access scope', function (): void {
    $clerk = staffUser('clerk@a.test', 'staff');
    $admin = staffUser('ada@a.test', 'admin');

    tenancy()->initialize($this->tenant);
    $scope = app(StaffAccessScope::class);
    app(TenantSettingsService::class)->set('staff_data_access_scope', 'own');

    $sql = static fn (User $u): string => $scope->apply(User::query(), $u, 'created_by_user_id')->toSql();

    expect($scope->mode($clerk))->toBe('own')
        ->and($sql($clerk))->toContain('created_by_user_id')
        ->and($scope->mode($admin))->toBe('all')
        ->and($sql($admin))->not->toContain('created_by_user_id');

    // Without warehouse assignments (built with warehouses), a narrowed
    // user sees no warehouse-scoped records.
    app(TenantSettingsService::class)->set('staff_data_access_scope', 'warehouse');
    $ids = null;
    $scope->apply(User::query(), $clerk, null, static function ($q, array $warehouseIds) use (&$ids): void {
        $ids = $warehouseIds;
        $q->whereIn('id', $warehouseIds);
    });
    expect($ids)->toBe([]);
});
