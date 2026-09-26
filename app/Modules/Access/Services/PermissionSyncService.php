<?php

declare(strict_types=1);

namespace App\Modules\Access\Services;

use App\Modules\Access\Support\PermissionPattern;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the canonical permission set and the default roles present, in the
 * landlord database (guard "platform") and in each tenant database (guard
 * "staff") (spec §12.2, §12.3, §12.6).
 *
 * Additive only: it never deletes a permission, and it never changes the
 * assignments of an unprotected role that already exists.
 */
final class PermissionSyncService
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @return list<string>
     */
    public function getCanonicalPermissionSet(string $context): array
    {
        return array_values((array) config("permissions.generated.{$context}", []));
    }

    /**
     * Sync the current tenant database (tenancy must be initialised).
     */
    public function syncPermissionsForTenant(): void
    {
        $guard = (string) config('permissions.tenant.guard', 'staff');
        $canonical = $this->getCanonicalPermissionSet('tenant');

        DB::transaction(function () use ($guard, $canonical): void {
            $this->insertMissingPermissions($canonical, $guard);

            foreach ((array) config('permissions.tenant.protected') as $name => $patterns) {
                $role = Role::findOrCreate($name, $guard);
                $role->syncPermissions(PermissionPattern::filter(
                    $canonical,
                    (array) $patterns['include'],
                    (array) $patterns['exclude'],
                ));
            }

            foreach ((array) config('permissions.tenant.roles') as $name => $patterns) {
                $this->createRoleIfMissing($name, $guard, PermissionPattern::filter($canonical, (array) $patterns));
            }
        });

        $this->registrar->forgetCachedPermissions();
    }

    /**
     * Sync the landlord database: platform permissions and roles (§7.6).
     */
    public function syncPermissionsForLandlord(): void
    {
        $guard = (string) config('permissions.landlord.guard', 'platform');
        $canonical = $this->getCanonicalPermissionSet('landlord');
        $superAdminOnly = (array) config('permissions.landlord.super_admin_only', []);

        DB::connection('landlord')->transaction(function () use ($guard, $canonical, $superAdminOnly): void {
            $this->insertMissingPermissions($canonical, $guard);

            foreach ((array) config('permissions.landlord.roles') as $name => $patterns) {
                if ($name === 'super-admin') {
                    Role::findOrCreate($name, $guard)->syncPermissions($canonical);

                    continue;
                }

                $this->createRoleIfMissing($name, $guard, PermissionPattern::filter($canonical, (array) $patterns, $superAdminOnly));
            }
        });

        $this->registrar->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $names
     */
    private function insertMissingPermissions(array $names, string $guard): void
    {
        $existing = Permission::query()->where('guard_name', $guard)->pluck('name')->all();
        $now = now();

        $missing = array_values(array_diff($names, $existing));

        foreach (array_chunk($missing, 500) as $chunk) {
            Permission::query()->insert(array_map(static fn (string $name): array => [
                'name' => $name,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        if ($missing !== []) {
            $this->registrar->forgetCachedPermissions();
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createRoleIfMissing(string $name, string $guard, array $permissions): void
    {
        if (Role::query()->where('name', $name)->where('guard_name', $guard)->exists()) {
            return;
        }

        Role::create(['name' => $name, 'guard_name' => $guard])->syncPermissions($permissions);
    }
}
