<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * An hour after a gateway refund whose outcome is unknown (spec §40.4):
 * never retried, staff are asked to check the provider. The daily tenant
 * maintenance runs the same check.
 */
final class CheckRefundOutcome implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $refundId,
    ) {
        $this->onQueue('tenant-default');
    }

    public function handle(OrderPaymentService $payments): void
    {
        Tenant::query()->find($this->tenantId)?->run(static fn () => $payments->flagUnresolvedRefunds());
    }
}
