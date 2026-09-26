<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Landlord\InitialPlatformAdminSeeder;
use Database\Seeders\Landlord\LandlordCmsSeeder;
use Database\Seeders\Landlord\LegalDocumentSeeder;
use Database\Seeders\Landlord\NotificationTemplateSeeder;
use Database\Seeders\Landlord\PlanCatalogueSeeder;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Database\Seeders\Landlord\PlatformSettingsSeeder;
use Database\Seeders\Landlord\ReferenceDataSeeder;
use Illuminate\Database\Seeder;

/**
 * Landlord seeding (spec §7.6). Runs on every deployment; every seeder is
 * idempotent and insert-only.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            PlatformAccessSeeder::class,
            PlatformSettingsSeeder::class,
            PlanCatalogueSeeder::class,
            NotificationTemplateSeeder::class,
            LegalDocumentSeeder::class,
            LandlordCmsSeeder::class,
            InitialPlatformAdminSeeder::class,
        ]);
    }
}
