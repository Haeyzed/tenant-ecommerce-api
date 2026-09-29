<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Jobs;

use App\Modules\ProductSubscriptions\Services\ProductSubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily: due subscriptions renewed and charged through their stored
 * authorization (spec §55.2 step 3). Runs under wind-down too (§11.5). One
 * try: a retry could charge twice; a failed renewal is retried tomorrow.
 */
final class ProcessProductSubscriptionRenewals implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct()
    {
        $this->onQueue('tenant-critical');
    }

    public function handle(ProductSubscriptionService $subscriptions): void
    {
        $subscriptions->processDueRenewals();
    }
}
