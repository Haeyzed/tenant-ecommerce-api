<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Jobs;

use App\Modules\GiftCards\Services\GiftCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily: active cards past expires_at become expired (spec §46.3).
 */
final class ExpireGiftCards implements ShouldQueue
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

    public function handle(GiftCardService $giftCards): void
    {
        $giftCards->expireGiftCards();
    }
}
