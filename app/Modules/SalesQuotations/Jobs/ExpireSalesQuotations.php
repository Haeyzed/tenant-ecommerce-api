<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Jobs;

use App\Modules\SalesQuotations\Services\SalesQuotationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily: sent quotations past valid_until expire (spec §53.2).
 */
final class ExpireSalesQuotations implements ShouldQueue
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

    public function handle(SalesQuotationService $quotations): void
    {
        $quotations->expireQuotations();
    }
}
