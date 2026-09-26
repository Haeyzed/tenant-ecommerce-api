<?php

declare(strict_types=1);

namespace App\Modules\Access\Services;

use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant roles (spec §12.3). owner and admin are protected: never deleted,
 * edited or stripped; the sync keeps their permissions complete.
 */
final readonly class RoleService
{
    public const string GUARD = 'staff';

    public const array PROTECTED_ROLES = ['owner', 'admin'];

    public function __construct(
        private ModuleRegistry $registry,
        private FeatureAccessService $features,
        private PermissionSyncService $sync,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listRoles(): Collection
    {
        return Role::query()
            ->where('guard_name', self::GUARD)
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get()
            ->map(static fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'protected' => in_array($role->name, self::PROTECTED_ROLES, true),
                'permissions_count' => $role->permissions_count,
                'users_count' => $role->users_count,
            ])
            ->toBase();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRole(array $data): Role
    {
        $validated = validator($data, [
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9 _-]*$/i', Rule::unique('tenant.roles', 'name')->where('guard_name', self::GUARD)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($validated): Role {
            /** @var Role $role */
            $role = Role::query()->create(['name' => $validated['name'], 'guard_name' => self::GUARD]);

            if (isset($validated['permissions'])) {
                $role->syncPermissions($this->assertCanonical($validated['permissions']));
            }

            ActivityRecorder::tenant('roles', "Role {$role->name} created", $role);

            return $role;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRole(Role $role, array $data): Role
    {
        $this->assertEditable($role);

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9 _-]*$/i', Rule::unique('tenant.roles', 'name')->where('guard_name', self::GUARD)->ignore($role->id)],
        ])->validate();

        if (in_array(strtolower($validated['name']), self::PROTECTED_ROLES, true)) {
            throw ApiException::unprocessable('role_protected', 'This name is reserved.');
        }

        $role->forceFill(['name' => $validated['name']])->save();
        $this->registrar->forgetCachedPermissions();
        ActivityRecorder::tenant('roles', "Role renamed to {$role->name}", $role);

        return $role;
    }

    public function deleteRole(Role $role): void
    {
        $this->assertEditable($role);

        if (DB::connection('tenant')->table('model_has_roles')->where('role_id', $role->id)->exists()) {
            throw ApiException::unprocessable('role_in_use', 'Remove this role from every user first.');
        }

        ActivityRecorder::tenant('roles', "Role {$role->name} deleted", null, ['role' => $role->name]);
        $role->delete();
        $this->registrar->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function assignPermissionsToRole(Role $role, array $permissionNames): Role
    {
        $this->assertEditable($role);

        $role->syncPermissions($this->assertCanonical($permissionNames));
        $this->registrar->forgetCachedPermissions();
        ActivityRecorder::tenant('roles', "Permissions of {$role->name} updated", $role, ['count' => count($permissionNames)]);

        return $role;
    }

    /**
     * The canonical set grouped by module: core permissions, plus every
     * module that is enabled, disabled or locked (with its state).
     *
     * @return list<array{module: string, name: string, state: string|null, permissions: list<string>}>
     */
    public function listPermissions(Tenant $tenant): array
    {
        $canonical = $this->sync->getCanonicalPermissionSet('tenant');
        $groups = ['core' => ['module' => 'core', 'name' => 'Core', 'state' => null, 'permissions' => []]];
        $states = $this->features->states($tenant);

        foreach ($canonical as $permission) {
            $module = $this->moduleFor($permission);

            if ($module === null) {
                $groups['core']['permissions'][] = $permission;

                continue;
            }

            $state = $states[$module] ?? ModuleState::Unavailable;

            if (! in_array($state, [ModuleState::Enabled, ModuleState::Disabled, ModuleState::Locked], true)) {
                continue;
            }

            $groups[$module] ??= ['module' => $module, 'name' => $this->registry->get($module)->name, 'state' => $state->value, 'permissions' => []];
            $groups[$module]['permissions'][] = $permission;
        }

        return array_values($groups);
    }

    /**
     * The module owning a permission, from the registry's permission_groups
     * (resource prefixes); null for core permissions.
     */
    public function moduleFor(string $permission): ?string
    {
        foreach ($this->registry->all() as $key => $definition) {
            foreach ($definition->permissionGroups as $group) {
                if ($permission === $group || str_starts_with($permission, $group.'.')) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function assertEditable(Role $role): void
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            throw ApiException::unprocessable('role_protected', 'The owner and admin roles cannot be changed.');
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function assertCanonical(array $names): array
    {
        $names = array_values(array_unique($names));
        $unknown = array_values(array_diff($names, $this->sync->getCanonicalPermissionSet('tenant')));

        if ($unknown !== []) {
            throw ApiException::unprocessable('permission_unknown', 'Unknown permissions.', ['permissions' => $unknown]);
        }

        // Canonical permissions always exist after the sync; create any a
        // release added before this tenant's next sync.
        foreach ($names as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        return $names;
    }
}
