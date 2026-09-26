<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Actions\SeedAction;

/**
 * World reference data (spec §20). The package import runs only while the
 * countries table is empty.
 */
final class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $table = config('world.migrations.countries.table_name', 'countries');

        if (DB::connection('landlord')->table($table)->exists()) {
            return;
        }

        $this->call(SeedAction::class);
    }
}
