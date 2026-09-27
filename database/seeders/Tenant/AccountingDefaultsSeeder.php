<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Accounting\Services\ChartOfAccountsService;
use Illuminate\Database\Seeder;

/**
 * The account categories and system accounts (spec §57.2, A-42), seeded
 * for every tenant whether or not `accounting` is enabled. Insert-only.
 */
final class AccountingDefaultsSeeder extends Seeder
{
    public function __construct(private readonly ChartOfAccountsService $accounts) {}

    public function run(): void
    {
        $this->accounts->seedDefaults();
    }
}
