<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Access\Services\PermissionSyncService;
use Illuminate\Database\Seeder;

/**
 * Platform permissions and roles (spec §7.6, §12.2). Additive only.
 */
final class PlatformAccessSeeder extends Seeder
{
    public function __construct(private readonly PermissionSyncService $permissions) {}

    public function run(): void
    {
        $this->permissions->syncPermissionsForLandlord();
    }
}
