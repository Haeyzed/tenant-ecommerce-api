<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Affiliates\Services\AffiliatePayoutService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Runs affiliate payout generation for a period by hand (spec §21A.6).
 * Idempotent per affiliate, currency and period.
 */
#[Signature('affiliates:generate-payouts {period_end : The last day of the period (YYYY-MM-DD, platform timezone)}')]
#[Description('Generate affiliate payouts for the period ending on the given date')]
final class GenerateAffiliatePayoutsCommand extends Command
{
    public function handle(AffiliatePayoutService $payouts): int
    {
        $periodEnd = (string) $this->argument('period_end');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) !== 1 || Carbon::canBeCreatedFromFormat($periodEnd, 'Y-m-d') === false) {
            $this->error('Give the period end as YYYY-MM-DD.');

            return self::INVALID;
        }

        $created = $payouts->generate(Carbon::createFromFormat('Y-m-d', $periodEnd));

        $this->info("{$created->count()} payout(s) created for the period ending {$periodEnd}.");

        return self::SUCCESS;
    }
}
