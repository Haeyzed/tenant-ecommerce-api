<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Jobs;

use App\Modules\Affiliates\Services\AffiliatePayoutService;
use App\Modules\Settings\Services\PlatformSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Monthly payout generation (spec §21A.6, §72.2). Scheduled hourly; acts
 * only on affiliate_payout_day from 04:00 in the platform timezone, for
 * the previous calendar month, and only when the schedule is "monthly".
 * Generation is idempotent per period, so later runs that day are no-ops.
 */
final class GenerateAffiliatePayouts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int RUN_HOUR = 4;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 900];

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(AffiliatePayoutService $payouts, PlatformSettingsService $settings): void
    {
        if ($settings->get('affiliate_payout_schedule', 'monthly') !== 'monthly') {
            return;
        }

        $now = CarbonImmutable::now($settings->timezone());

        if ($now->day !== (int) $settings->get('affiliate_payout_day', 1) || $now->hour < self::RUN_HOUR) {
            return;
        }

        $payouts->generate($now->subMonthNoOverflow()->endOfMonth());
    }
}
