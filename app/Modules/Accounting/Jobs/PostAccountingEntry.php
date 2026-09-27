<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Jobs;

use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Posts one accounting outbox request (spec §57.3 step 3). Idempotent: the
 * request row is locked and the journal posting_key is unique. Failures
 * are recorded on the request, not retried by the queue.
 */
final class PostAccountingEntry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $requestId,
    ) {
        $this->onQueue('tenant-default');
    }

    public function handle(): void
    {
        Tenant::query()->find($this->tenantId)?->run(fn () => app(AccountingService::class)->processRequest($this->requestId));
    }
}
