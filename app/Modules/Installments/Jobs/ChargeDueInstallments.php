<?php

declare(strict_types=1);

namespace App\Modules\Installments\Jobs;

use App\Modules\Installments\Services\InstallmentPlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily: pending installments due today charged through the stored
 * authorization (spec §47.3 step 4). One try: a failed charge is recorded
 * as failed and the customer can pay; a retry could charge twice.
 */
final class ChargeDueInstallments implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('tenant-critical');
    }

    public function handle(InstallmentPlanService $plans): void
    {
        $plans->chargeDueInstallments();
    }
}
