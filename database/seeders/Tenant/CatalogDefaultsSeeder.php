<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Catalog\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * The default unit "Piece" (spec §29.1). Insert-only.
 */
final class CatalogDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        if (! UnitOfMeasure::query()->where('short_code', UnitOfMeasure::DEFAULT_CODE)->exists()) {
            UnitOfMeasure::query()->create(['name' => 'Piece', 'short_code' => UnitOfMeasure::DEFAULT_CODE, 'allows_decimal' => false]);
        }
    }
}
