<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Currency\Services\CurrencyService;
use Illuminate\Database\Seeder;

/**
 * The base tenant_currencies row (spec §48.1), seeded for every tenant
 * whether or not `multi_currency` is enabled, so the invariant always
 * holds. Insert-only.
 */
final class CurrencyDefaultsSeeder extends Seeder
{
    public function __construct(private readonly CurrencyService $currencies) {}

    public function run(): void
    {
        $this->currencies->ensureBase();
    }
}
