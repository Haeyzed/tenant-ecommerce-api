<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Customers\Models\CustomerGroup;
use Illuminate\Database\Seeder;

/**
 * The default customer group, "Standard" (spec §26.3). Insert-only: a
 * tenant that already has a default group keeps it, whatever its name.
 */
final class CustomerDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        if (CustomerGroup::query()->where('is_default', true)->exists()) {
            return;
        }

        $group = CustomerGroup::query()->firstOrNew(['name' => 'Standard']);
        $group->forceFill(['is_default' => true])->save();
    }
}
