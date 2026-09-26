<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Modules\Access\Services\PermissionSyncService;
use App\Modules\Access\Services\RoleService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantManagementService;
use App\Modules\Users\Models\User;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant staff users (spec §25.2). max_users is enforced on the create and
 * reactivate routes by usage.limit (under the per-tenant limit lock). The
 * owner role moves only by ownership transfer (§12.3).
 */
final readonly class UserService
{
    public function __construct(
        private PermissionSyncService $permissions,
        private PermissionRegistrar $registrar,
        private NotificationDispatchService $notifications,
        private TenantManagementService $tenants,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @param  array{search?: string, role?: string, is_active?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function listUsers(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->with('roles:id,name')
            ->when($filters['search'] ?? null, static fn ($q, $v) => $q->where(static fn ($q) => $q->where('name', 'like', '%'.$v.'%')->orWhere('email', 'like', '%'.$v.'%')))
            ->when($filters['role'] ?? null, static fn ($q, $v) => $q->whereHas('roles', static fn ($r) => $r->where('name', $v)))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function getUser(User $user): User
    {
        return $user->load('roles:id,name', 'permissions:id,name');
    }

    /**
     * Creates a staff user with the given roles. Without a password, the
     * user receives a link to set one (the password-reset flow).
     *
     * @param  array<string, mixed>  $data
     */
    public function createUser(array $data, User $by): User
    {
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('tenant.users', 'email')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'password' => ['sometimes', 'nullable', 'string', PasswordRule::defaults()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string'],
        ])->validate();

        $roles = $this->assignableRoles($validated['roles']);

        $user = DB::connection('tenant')->transaction(static function () use ($validated, $roles): User {
            $user = new User([
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                // An unusable random password until the invitee sets theirs.
                'password' => $validated['password'] ?? bin2hex(random_bytes(32)),
                'is_active' => true,
            ]);
            $user->save();
            $user->syncRoles($roles);

            return $user;
        });

        $this->registrar->forgetCachedPermissions();
        ActivityRecorder::tenant('users', "Staff user {$user->email} created", $user, ['roles' => $roles], $by);

        if (($validated['password'] ?? null) === null) {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
        }

        return $user->load('roles:id,name');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateUser(User $user, array $data, User $by): User
    {
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }

        $validated = validator($data, [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'string', 'email:rfc', 'max:255', Rule::unique('tenant.users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ])->validate();

        $user->fill($validated)->save();

        // The owner is the platform's billing contact for the store.
        if ($user->isOwner() && (isset($validated['email']) || isset($validated['name']))) {
            $this->tenants->updateOwnerContact($this->tenant(), $user->name, $user->email);
        }

        ActivityRecorder::tenant('users', "Staff user {$user->email} updated", $user, ['fields' => array_keys($validated)], $by);

        return $user->load('roles:id,name');
    }

    public function deactivateUser(User $user, User $by): User
    {
        $this->assertNotOwner($user, 'deactivated');
        $this->assertNotSelf($user, $by, 'deactivate');

        $user->forceFill(['is_active' => false])->save();
        $user->tokens()->delete();
        ActivityRecorder::tenant('users', "Staff user {$user->email} deactivated", $user, [], $by);

        return $user;
    }

    public function reactivateUser(User $user, User $by): User
    {
        $user->forceFill(['is_active' => true])->save();
        ActivityRecorder::tenant('users', "Staff user {$user->email} reactivated", $user, [], $by);

        return $user;
    }

    public function deleteUser(User $user, User $by): void
    {
        $this->assertNotOwner($user, 'deleted');
        $this->assertNotSelf($user, $by, 'delete');

        DB::connection('tenant')->transaction(static function () use ($user): void {
            $user->tokens()->delete();
            $user->forceFill(['is_active' => false])->save();
            $user->delete();
        });

        ActivityRecorder::tenant('users', "Staff user {$user->email} deleted", $user, [], $by);
    }

    /**
     * Replaces the user's roles. owner can never be given or taken here,
     * and the owner always keeps it.
     *
     * @param  list<string>  $roles
     */
    public function syncRoles(User $user, array $roles, User $by): User
    {
        $roles = $this->assignableRoles($roles);

        if ($user->isOwner()) {
            $roles[] = 'owner';
        }

        $user->syncRoles(array_values(array_unique($roles)));
        $this->registrar->forgetCachedPermissions();
        ActivityRecorder::tenant('users', "Roles of {$user->email} updated", $user, ['roles' => $roles], $by);

        return $user->load('roles:id,name');
    }

    public function assignRole(User $user, string $role, User $by): User
    {
        return $this->syncRoles($user, [...$user->getRoleNames()->reject(static fn (string $r): bool => $r === 'owner')->all(), $role], $by);
    }

    public function revokeRole(User $user, string $role, User $by): User
    {
        return $this->syncRoles($user, $user->getRoleNames()->reject(static fn (string $r): bool => $r === $role || $r === 'owner')->values()->all(), $by);
    }

    /**
     * Direct grants that bypass roles (§25.2), from the canonical set only.
     *
     * @param  list<string>  $permissions
     */
    public function syncDirectPermissions(User $user, array $permissions, User $by): User
    {
        $permissions = array_values(array_unique($permissions));
        $unknown = array_values(array_diff($permissions, $this->permissions->getCanonicalPermissionSet('tenant')));

        if ($unknown !== []) {
            throw ApiException::unprocessable('permission_unknown', 'Unknown permissions.', ['permissions' => $unknown]);
        }

        $user->syncPermissions($permissions);
        $this->registrar->forgetCachedPermissions();
        ActivityRecorder::tenant('users', "Direct permissions of {$user->email} updated", $user, ['permissions' => $permissions], $by);

        return $user->load('roles:id,name', 'permissions:id,name');
    }

    public function assignDirectPermission(User $user, string $permission, User $by): User
    {
        return $this->syncDirectPermissions($user, [...$user->getDirectPermissions()->pluck('name')->all(), $permission], $by);
    }

    public function revokeDirectPermission(User $user, string $permission, User $by): User
    {
        return $this->syncDirectPermissions($user, $user->getDirectPermissions()->pluck('name')->reject(static fn (string $p): bool => $p === $permission)->values()->all(), $by);
    }

    /**
     * Role permissions and direct permissions, merged.
     *
     * @return list<string>
     */
    public function getEffectivePermissions(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->unique()->sort()->values()->all();
    }

    /**
     * Moves owner to another active staff user; the previous owner becomes
     * admin, the landlord billing contact follows, and both are told
     * (§12.3). Requires the current owner's password.
     */
    public function transferOwnership(User $newOwner, User $currentOwner, string $currentPassword): User
    {
        if (! $currentOwner->isOwner()) {
            throw ApiException::forbidden('not_owner', 'Only the store owner can transfer ownership.');
        }

        if (! Hash::check($currentPassword, $currentOwner->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is not correct.']]);
        }

        if ($newOwner->is($currentOwner)) {
            throw ApiException::unprocessable('ownership_same_user', 'You already own this store.');
        }

        if (! $newOwner->is_active) {
            throw ApiException::unprocessable('user_inactive', 'Ownership can only move to an active staff user.');
        }

        DB::connection('tenant')->transaction(static function () use ($newOwner, $currentOwner): void {
            // Serialise concurrent transfers on the owner row.
            User::query()->whereKey($currentOwner->id)->lockForUpdate()->first();

            $currentOwner->removeRole('owner');
            $currentOwner->assignRole('admin');
            $newOwner->syncRoles(['owner']);
        });

        $this->registrar->forgetCachedPermissions();
        $this->tenants->updateOwnerContact($this->tenant(), $newOwner->name, $newOwner->email);

        ActivityRecorder::tenant('users', 'Store ownership transferred', $newOwner, ['from' => $currentOwner->email, 'to' => $newOwner->email], $currentOwner);

        $this->notifications->dispatch('staff.ownership_transferred', [$newOwner, $currentOwner], [
            'store_name' => (string) ($this->settings->get('store_name') ?: $this->tenant()->name),
            'previous_owner' => $currentOwner->name,
            'new_owner' => $newOwner->name,
        ]);

        return $newOwner->load('roles:id,name');
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function assignableRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_map('strval', $roles)));

        if (in_array('owner', $roles, true)) {
            throw ApiException::unprocessable('role_owner_not_assignable', 'The owner role moves only by ownership transfer.');
        }

        $known = Role::query()->where('guard_name', RoleService::GUARD)->whereIn('name', $roles)->pluck('name')->all();
        $unknown = array_values(array_diff($roles, $known));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['roles' => ['Unknown roles: '.implode(', ', $unknown).'.']]);
        }

        return $roles;
    }

    private function assertNotOwner(User $user, string $verb): void
    {
        if ($user->isOwner()) {
            throw ApiException::unprocessable('owner_protected', "The owner cannot be {$verb}. Transfer ownership first.");
        }
    }

    private function assertNotSelf(User $user, User $by, string $verb): void
    {
        if ($user->is($by)) {
            throw ApiException::unprocessable('cannot_modify_self', "You cannot {$verb} your own account.");
        }
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }
}
