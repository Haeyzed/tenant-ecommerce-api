<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Cms\Support\CmsDefaults;
use Illuminate\Database\Seeder;

/**
 * The storefront's system pages and menus (spec §24.6). Insert-only.
 */
final class CmsDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        CmsDefaults::seed();
    }
}
