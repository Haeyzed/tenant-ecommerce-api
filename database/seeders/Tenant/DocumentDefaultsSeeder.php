<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Documents\Services\InvoiceTemplateDefaults;
use Illuminate\Database\Seeder;

/**
 * The default A4 invoice template and sticker layout (spec §9.4, §43),
 * only when the store has none.
 */
final class DocumentDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        InvoiceTemplateDefaults::seed();
    }
}
