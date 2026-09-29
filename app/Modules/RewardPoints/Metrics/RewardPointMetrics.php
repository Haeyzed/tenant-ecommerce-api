<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints\Metrics;

use App\Modules\RewardPoints\Models\RewardPointTransaction;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The reward-points section (spec §44.3): points issued (earned and
 * positive adjustments), redeemed and expired in the range, and the points
 * customers hold now.
 */
final class RewardPointMetrics
{
    public function rewardPoints(DateRange $range, MetricsScope $scope): SectionResult
    {
        $comparison = $range->comparison();
        $figures = $this->figures($range);
        $previous = $comparison === null ? null : $this->figures($comparison);

        return new SectionResult(
            kpis: [
                KpiValue::count('points_issued', 'Points issued', $figures['issued'], $range, $previous['issued'] ?? null, KpiValue::NEUTRAL),
                KpiValue::count('points_redeemed', 'Points redeemed', $figures['redeemed'], $range, $previous['redeemed'] ?? null, KpiValue::UP_IS_GOOD),
                KpiValue::count('points_expired', 'Points expired', $figures['expired'], $range, $previous['expired'] ?? null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('points_outstanding', 'Points outstanding', (int) DB::connection('tenant')->table('customer_reward_points')->sum('points_balance'), $range, null, KpiValue::NEUTRAL),
            ],
        );
    }

    /**
     * @return array{issued: int, redeemed: int, expired: int}
     */
    private function figures(DateRange $range): array
    {
        $row = DB::connection('tenant')->table('reward_point_transactions')->whereBetween('created_at', [$range->startUtc(), $range->endUtc()])
            ->selectRaw('SUM(CASE WHEN points > 0 AND type IN (?, ?) THEN points ELSE 0 END) AS issued', [RewardPointTransaction::EARNED, RewardPointTransaction::ADJUSTED])
            ->selectRaw('SUM(CASE WHEN type = ? THEN -points ELSE 0 END) AS redeemed', [RewardPointTransaction::REDEEMED])
            ->selectRaw('SUM(CASE WHEN type = ? THEN -points ELSE 0 END) AS expired', [RewardPointTransaction::EXPIRED])
            ->first();

        return ['issued' => (int) ($row->issued ?? 0), 'redeemed' => (int) ($row->redeemed ?? 0), 'expired' => (int) ($row->expired ?? 0)];
    }
}
