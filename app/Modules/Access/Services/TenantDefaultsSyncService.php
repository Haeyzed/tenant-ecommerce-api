<?php

declare(strict_types=1);

namespace App\Modules\Access\Services;

use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\Tenant\NotificationTemplateSeeder;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The versioned, idempotent, additive-only sync of every tenant's default
 * data (spec §12.6). Runs in the tenant context: at provisioning, and for
 * every tenant on each deployment.
 */
final class TenantDefaultsSyncService
{
    /**
     * Seeders run in this order after the permission and role sync. Each is
     * insert-only and never overwrites a tenant's changes (spec §9.5).
     *
     * @var list<class-string<Seeder>>
     */
    private const array SEEDERS = [
        NotificationTemplateSeeder::class,
    ];

    public function __construct(
        private readonly PermissionSyncService $permissions,
        private readonly Container $container,
    ) {}

    public function sync(Tenant $tenant): void
    {
        if (tenant()?->getTenantKey() !== $tenant->getTenantKey()) {
            throw new RuntimeException('TenantDefaultsSyncService::sync() must run inside the tenant context.');
        }

        $this->permissions->syncPermissionsForTenant();

        foreach (self::SEEDERS as $seeder) {
            $this->container->make($seeder)->run();
        }

        // Step 5: additive module defaults for every enabled module.
        $features = $this->container->make(FeatureAccessService::class);
        $registry = $this->container->make(ModuleRegistry::class);

        foreach ($features->states($tenant) as $key => $state) {
            if ($state === ModuleState::Enabled) {
                $registry->lifecycle($key)?->seedDefaults();
            }
        }

        $tenant->forceFill(['permissions_version' => $this->version()])->saveQuietly();
    }

    public function version(): string
    {
        return (string) config('permissions.tenant.version');
    }
}
