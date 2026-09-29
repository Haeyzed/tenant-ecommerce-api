<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Metrics;

use App\Modules\Manufacturing\Models\WorkOrder;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The manufacturing section (spec §44.3): work orders planned and in
 * progress now, and completed in the range (within the viewer's warehouses).
 */
final readonly class ManufacturingMetrics
{
    public function manufacturing(DateRange $range, MetricsScope $scope): SectionResult
    {
        $count = fn (string $status) => $this->scoped($scope)->where('status', $status)->count();
        $comparison = $range->comparison();

        return new SectionResult(kpis: [
            KpiValue::count('planned', 'Planned', $count(WorkOrder::PLANNED), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('in_progress', 'In progress', $count(WorkOrder::IN_PROGRESS), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('completed', 'Completed', $this->completed($range, $scope), $range, $comparison === null ? null : $this->completed($comparison, $scope), KpiValue::UP_IS_GOOD),
        ]);
    }

    private function completed(DateRange $range, MetricsScope $scope): int
    {
        return $this->scoped($scope)->where('status', WorkOrder::COMPLETED)->whereBetween('completed_at', [$range->startUtc(), $range->endUtc()])->count();
    }

    private function scoped(MetricsScope $scope): Builder
    {
        return DB::connection('tenant')->table('work_orders')
            ->when($scope->isNarrowed(), static fn ($q) => $q->whereIn('warehouse_id', $scope->warehouseIds ?: [0]));
    }
}
