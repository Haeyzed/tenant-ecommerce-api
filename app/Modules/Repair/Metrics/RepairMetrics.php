<?php

declare(strict_types=1);

namespace App\Modules\Repair\Metrics;

use App\Modules\Repair\Models\RepairJob;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The repair section (spec §44.3): jobs in the workshop and waiting for
 * approval now, and jobs completed in the range, within the viewer's
 * locations.
 */
final readonly class RepairMetrics
{
    public function repair(DateRange $range, MetricsScope $scope): SectionResult
    {
        $jobs = fn () => DB::connection('tenant')->table('repair_jobs')
            ->when($scope->isNarrowed(), static fn ($q) => $q->whereIn('warehouse_id', $scope->warehouseIds ?: [0]));
        $completed = fn (DateRange $r): int => $jobs()->whereNotNull('completed_at')->where('status', '!=', RepairJob::CANCELLED)
            ->whereBetween('completed_at', [$r->startUtc(), $r->endUtc()])->count();
        $comparison = $range->comparison();

        return new SectionResult(kpis: [
            KpiValue::count('in_workshop', 'In the workshop', $jobs()->whereIn('status', [RepairJob::RECEIVED, RepairJob::DIAGNOSING, RepairJob::IN_REPAIR])->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('awaiting_approval', 'Awaiting approval', $jobs()->where('status', RepairJob::AWAITING_APPROVAL)->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('completed', 'Completed', $completed($range), $range, $comparison === null ? null : $completed($comparison)),
        ]);
    }
}
