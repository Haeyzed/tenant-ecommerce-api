<?php

declare(strict_types=1);

namespace App\Modules\Installments\Jobs;

use App\Modules\Installments\Services\InstallmentPlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily: unpaid installments past their due date become overdue; plans
 * past the overdue threshold are flagged defaulted (spec §47.3 step 5).
 */
final class MarkOverdueInstallments implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 1800];

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('tenant-default');
    }

    public function handle(InstallmentPlanService $plans): void
    {
        $plans->markOverdueInstallments();
    }
}
