<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Access\Services\TenantDefaultsSyncService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Entry point of `tenants:seed` (config tenancy.seeder_parameters). The
 * defaults sync is the single source of tenant default data (spec §12.6).
 */
final class TenantDatabaseSeeder extends Seeder
{
    public function __construct(private readonly TenantDefaultsSyncService $defaults) {}

    public function run(): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('TenantDatabaseSeeder runs only in the tenant context.');
        }

        $this->defaults->sync($tenant);
    }
}
