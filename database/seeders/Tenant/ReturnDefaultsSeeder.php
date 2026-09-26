<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Modules\Returns\Services\ReturnReasonService;
use Illuminate\Database\Seeder;

/**
 * Default return reasons (spec §41.1), only when the store has none.
 */
final class ReturnDefaultsSeeder extends Seeder
{
    public function __construct(private readonly ReturnReasonService $reasons) {}

    public function run(): void
    {
        $this->reasons->seedDefaults();
    }
}
