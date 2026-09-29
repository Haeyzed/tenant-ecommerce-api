<?php

declare(strict_types=1);

namespace App\Modules\Support\Metrics;

use App\Modules\Support\Models\SupportConversation;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The support section (spec §44.3): open (open and pending) and unassigned
 * conversations now, and conversations resolved in the range (last
 * activity of a resolved or closed conversation).
 */
final readonly class SupportMetrics
{
    public function support(DateRange $range, MetricsScope $scope): SectionResult
    {
        $comparison = $range->comparison();

        return new SectionResult(kpis: [
            KpiValue::count('open', 'Open', $this->count([SupportConversation::OPEN, SupportConversation::PENDING]), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('unassigned', 'Unassigned', $this->unassigned(), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('resolved', 'Resolved', $this->resolved($range), $range, $comparison === null ? null : $this->resolved($comparison), KpiValue::UP_IS_GOOD),
        ]);
    }

    /**
     * The conversations list strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        return [
            KpiValue::count('open', 'Open', $this->count([SupportConversation::OPEN]), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('pending', 'Pending', $this->count([SupportConversation::PENDING]), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('unassigned', 'Unassigned', $this->unassigned(), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('resolved', 'Resolved', $this->resolved($range), $range),
        ];
    }

    /**
     * @param  list<string>  $statuses
     */
    private function count(array $statuses): int
    {
        return DB::connection('tenant')->table('support_conversations')->whereIn('status', $statuses)->count();
    }

    private function unassigned(): int
    {
        return DB::connection('tenant')->table('support_conversations')->whereIn('status', [SupportConversation::OPEN, SupportConversation::PENDING])
            ->whereNull('assigned_to_user_id')->count();
    }

    private function resolved(DateRange $range): int
    {
        return DB::connection('tenant')->table('support_conversations')->whereIn('status', [SupportConversation::RESOLVED, SupportConversation::CLOSED])
            ->whereBetween('updated_at', [$range->startUtc(), $range->endUtc()])->count();
    }
}
