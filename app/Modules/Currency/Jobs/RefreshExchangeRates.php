<?php

declare(strict_types=1);

namespace App\Modules\Currency\Jobs;

use App\Modules\Currency\Services\CurrencyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The daily reference-rate refresh of one tenant (spec §48.2, §72.2):
 * base-to-active pairs only; manual rates are kept. Dispatched in the
 * tenant's context by its daily maintenance while multi_currency is enabled
 * and a provider is configured (UD-19).
 */
final class RefreshExchangeRates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 1800];

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('tenant-default');
    }

    public function handle(CurrencyService $currencies): void
    {
        if ($currencies->enabled()) {
            $currencies->refreshExchangeRates();
        }
    }
}
