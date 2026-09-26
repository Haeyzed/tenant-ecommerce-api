<?php

declare(strict_types=1);

namespace App\Modules\Billing\Metrics;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use Illuminate\Support\Facades\DB;

/**
 * Plan distribution and movement (spec §22.6 "plans"). Paying tenants per
 * plan combine the MRR ledger (who pays) with the current subscription
 * (which plan).
 */
final readonly class PlanMetrics
{
    public function __construct(private SubscriptionMetrics $subscriptions) {}

    private const array CURRENT = [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value];

    public function plans(DateRange $range): SectionResult
    {
        $comparison = $range->comparison();
        $paying = $this->payingTenantsByPlan();
        [$upgrades, $downgrades] = $this->subscriptions->planChanges($range);
        $previous = $comparison === null ? null : $this->subscriptions->planChanges($comparison);

        $kpis = [];

        foreach ($paying as $plan) {
            $kpis[] = KpiValue::count('paying_tenants_'.$plan['slug'], "Paying tenants: {$plan['name']}", $plan['tenants'], $range, null, KpiValue::UP_IS_GOOD);
        }

        return new SectionResult(
            kpis: [
                ...$kpis,
                KpiValue::count('upgrades', 'Upgrades', $upgrades, $range, $previous[0] ?? null),
                KpiValue::count('downgrades', 'Downgrades', $downgrades, $range, $previous[1] ?? null, KpiValue::DOWN_IS_GOOD),
            ],
            charts: [$this->distribution()],
            tables: [$this->movementMatrix($range)],
        );
    }

    /**
     * Tenants with positive MRR, by the plan of their current
     * subscription; every plan with a current subscription is listed.
     *
     * @return list<array{plan_id: int, slug: string, name: string, tenants: int}>
     */
    public function payingTenantsByPlan(): array
    {
        $paying = DB::connection('landlord')->table('subscription_mrr_movements')
            ->groupBy('tenant_id')
            ->havingRaw('SUM(mrr_delta) > 0')
            ->select('tenant_id');

        return DB::connection('landlord')->table('plans')
            ->leftJoin('subscriptions', static fn ($j) => $j->on('subscriptions.plan_id', '=', 'plans.id')->whereIn('subscriptions.status', self::CURRENT))
            ->leftJoinSub($paying, 'paying', 'paying.tenant_id', '=', 'subscriptions.tenant_id')
            ->groupBy('plans.id', 'plans.slug', 'plans.name', 'plans.sort_order')
            ->orderBy('plans.sort_order')
            ->selectRaw('plans.id, plans.slug, plans.name, COUNT(DISTINCT paying.tenant_id) as tenants')
            ->get()
            ->map(static fn ($r): array => ['plan_id' => (int) $r->id, 'slug' => (string) $r->slug, 'name' => (string) $r->name, 'tenants' => (int) $r->tenants])
            ->all();
    }

    private function distribution(): ChartSeries
    {
        $points = DB::connection('landlord')->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', self::CURRENT)
            ->groupBy('plans.id', 'plans.name', 'plans.sort_order')
            ->orderBy('plans.sort_order')
            ->selectRaw('plans.name, COUNT(DISTINCT subscriptions.tenant_id) as tenants')
            ->get()
            ->map(static fn ($r): array => ['x' => (string) $r->name, 'y' => (int) $r->tenants])
            ->all();

        return new ChartSeries('plan_distribution', 'Tenants by plan', ChartSeries::DONUT, KpiValue::COUNT, [
            ['key' => 'tenants', 'label' => 'Tenants', 'points' => $points],
        ]);
    }

    /**
     * From → to pairs of plan changes in the range: each plan-change
     * movement paired with the plan of the tenant's preceding movement.
     */
    private function movementMatrix(DateRange $range): TableBlock
    {
        $sequenced = DB::connection('landlord')->table('subscription_mrr_movements')
            ->selectRaw('plan_id, reason, occurred_at, LAG(plan_id) OVER (PARTITION BY tenant_id ORDER BY occurred_at, id) as from_plan_id');

        $rows = DB::connection('landlord')->query()->fromSub($sequenced, 'm')
            ->join('plans as from_plan', 'from_plan.id', '=', 'm.from_plan_id')
            ->join('plans as to_plan', 'to_plan.id', '=', 'm.plan_id')
            ->where('m.reason', 'plan_change')
            ->whereColumn('m.from_plan_id', '!=', 'm.plan_id')
            ->whereBetween('m.occurred_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('from_plan.name', 'to_plan.name')
            ->selectRaw('from_plan.name as from_plan, to_plan.name as to_plan, COUNT(*) as changes')
            ->orderByDesc('changes')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => ['from_plan' => $r->from_plan, 'to_plan' => $r->to_plan, 'changes' => (int) $r->changes])
            ->all();

        return new TableBlock('plan_movement_matrix', 'Plan changes', [
            ['key' => 'from_plan', 'label' => 'From', 'format' => 'text'],
            ['key' => 'to_plan', 'label' => 'To', 'format' => 'text'],
            ['key' => 'changes', 'label' => 'Changes', 'format' => 'count'],
        ], $rows, '/admin/subscriptions');
    }
}
