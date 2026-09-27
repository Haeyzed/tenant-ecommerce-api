<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Jobs;

use App\Modules\Purchasing\Services\QuotationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The daily expiry of supplier quotations past valid_until (spec §49.3,
 * §72.2), dispatched in the tenant's context by its daily maintenance while
 * purchasing is enabled.
 */
final class ExpireSupplierQuotations implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 1800];

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('tenant-default');
    }

    public function handle(QuotationService $quotations): void
    {
        $quotations->expireQuotations();
    }
}
