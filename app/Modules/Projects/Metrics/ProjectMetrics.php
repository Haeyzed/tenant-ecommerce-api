<?php

declare(strict_types=1);

namespace App\Modules\Projects\Metrics;

use App\Modules\Projects\Models\Project;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The projects section (spec §44.3): active projects (in progress), open
 * tasks past their end date, and projects completed in the range.
 */
final readonly class ProjectMetrics
{
    public function projects(DateRange $range, MetricsScope $scope): SectionResult
    {
        $comparison = $range->comparison();

        return new SectionResult(kpis: [
            KpiValue::count('active', 'Active projects', $this->withStatus('in_progress'), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('overdue_tasks', 'Overdue tasks', $this->overdueTasks(), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('completed', 'Completed', $this->completed($range), $range, $comparison === null ? null : $this->completed($comparison), KpiValue::UP_IS_GOOD),
        ]);
    }

    /**
     * The projects list strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        return [
            KpiValue::count('active', 'Active', $this->withStatus('in_progress'), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('on_hold', 'On hold', $this->withStatus('on_hold'), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('overdue_tasks', 'Overdue tasks', $this->overdueTasks(), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('completed', 'Completed', $this->completed($range), $range),
        ];
    }

    private function withStatus(string $status): int
    {
        return DB::connection('tenant')->table('projects')->where('status', $status)->count();
    }

    private function overdueTasks(): int
    {
        return DB::connection('tenant')->table('project_tasks')->whereNotIn('status', Project::CLOSED)
            ->whereNotNull('end_date')->whereDate('end_date', '<', today())->count();
    }

    private function completed(DateRange $range): int
    {
        return DB::connection('tenant')->table('projects')->where('status', Project::COMPLETED)
            ->whereBetween('completed_at', [$range->startUtc(), $range->endUtc()])->count();
    }
}
