<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Metrics;

use App\Modules\Reviews\Models\ProductReview;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Support\Facades\DB;

/**
 * Review figures for the reviews list (spec §44.4): pending moderation and
 * the average approved rating now; new reviews over the range.
 */
final readonly class ReviewMetrics
{
    /**
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $row = DB::connection('tenant')->table('product_reviews')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending, AVG(CASE WHEN status = ? THEN rating END) as average', [ProductReview::PENDING, ProductReview::APPROVED])
            ->first();
        $new = fn (DateRange $r): int => (int) (TimeSeries::total(DB::connection('tenant')->table('product_reviews'), 'created_at', $r, 'COUNT(*)')[''] ?? 0);
        $comparison = $range->comparison();
        $average = $row->average === null ? null : bcadd((string) round((float) $row->average, 2), '0', 2);

        return [
            KpiValue::count('pending_reviews', 'Pending moderation', (int) ($row->pending ?? 0), $range, null, KpiValue::DOWN_IS_GOOD),
            new KpiValue('average_rating', 'Average rating', $average, KpiValue::RATIO),
            KpiValue::count('new_reviews', 'New reviews', $new($range), $range, $comparison === null ? null : $new($comparison)),
        ];
    }
}
