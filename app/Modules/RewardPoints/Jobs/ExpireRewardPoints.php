<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints\Jobs;

use App\Modules\RewardPoints\Services\RewardPointService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily: earned points past their expiry (spec §54.2, A-38).
 */
final class ExpireRewardPoints implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 1800];

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('tenant-default');
    }

    public function handle(RewardPointService $points): void
    {
        $points->expirePoints();
    }
}
