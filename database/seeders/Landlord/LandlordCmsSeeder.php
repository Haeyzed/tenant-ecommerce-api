<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Cms\Support\CmsDefaults;
use Illuminate\Database\Seeder;

/**
 * The platform website's system pages and menus (spec §24.6, §7.6), all
 * drafts. Insert-only.
 */
final class LandlordCmsSeeder extends Seeder
{
    public function run(): void
    {
        CmsDefaults::seed();
    }
}
