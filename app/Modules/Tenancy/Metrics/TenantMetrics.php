<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Metrics;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Dashboard\Models\PlatformDailyMetric;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tenant figures of the landlord dashboard (spec §22.5 "Tenants", §22.6).
 * Aggregate SQL on landlord tables only; cross-tenant usage comes from
 * tenant_usage_snapshots, never from tenant databases.
 */
final class TenantMetrics
{
    private const array PENDING = ['awaiting_payment', 'provisioning', 'provisioning_failed'];

    /**
     * Days a snapshot stays "latest" for a tenant; older tenants (closed,
     * purged or not reporting) drop out of the top lists.
     */
    private const int SNAPSHOT_FRESH_DAYS = 7;

    public function overview(DateRange $range): SectionResult
    {
        $counts = $this->statusCounts();
        $new = $this->newTenantSeries($range);

        return new SectionResult(
            kpis: [
                KpiValue::count('active_tenants', 'Active tenants', $counts['active'], $range, $this->historyCount($range, ['active'])),
            ],
            charts: [
                new ChartSeries('new_tenants', 'New tenants', ChartSeries::BAR, KpiValue::COUNT, [
                    ['key' => 'new', 'label' => 'New tenants', 'points' => TimeSeries::points($new, true)],
                ], $range->interval),
            ],
            tables: [$this->latestSignUps()],
        );
    }

    public function tenants(DateRange $range): SectionResult
    {
        $counts = $this->statusCounts();
        $total = array_sum($counts);
        $pending = array_sum(array_intersect_key($counts, array_flip(self::PENDING)));

        $new = $this->newTenantSeries($range);
        $closed = TimeSeries::aggregate($this->tenantsTable(), 'closed_at', $range, 'COUNT(*)')[''] ?? [];
        $newTotal = (int) TimeSeries::sum($new);
        $closedTotal = (int) TimeSeries::sum($closed);

        $comparison = $range->comparison();
        $prevNew = $comparison === null ? null : (int) (TimeSeries::total($this->tenantsTable(), 'created_at', $comparison, 'COUNT(*)')[''] ?? 0);
        $prevClosed = $comparison === null ? null : (int) (TimeSeries::total($this->tenantsTable(), 'closed_at', $comparison, 'COUNT(*)')[''] ?? 0);

        return new SectionResult(
            kpis: [
                KpiValue::count('total_tenants', 'Total tenants', $total, $range, $this->historyCount($range, array_keys($counts)), KpiValue::NEUTRAL),
                KpiValue::count('active_tenants', 'Active tenants', $counts['active'], $range, $this->historyCount($range, ['active'])),
                KpiValue::count('trial_tenants', 'Trial tenants', $this->trialTenants(), $range, null, KpiValue::NEUTRAL),
                KpiValue::count('pending_tenants', 'Pending tenants', $pending, $range, $this->historyCount($range, self::PENDING), KpiValue::NEUTRAL),
                KpiValue::count('suspended_tenants', 'Suspended tenants', $counts['suspended'], $range, $this->historyCount($range, ['suspended']), KpiValue::DOWN_IS_GOOD),
                KpiValue::count('cancelled_tenants', 'Cancelled tenants', $this->cancelledTenants(), $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('new_tenants', 'New tenants', $newTotal, $range, $prevNew, KpiValue::UP_IS_GOOD, TimeSeries::sparkline($new, true)),
                KpiValue::count('tenant_growth', 'Tenant growth', $newTotal - $closedTotal, $range, $prevNew === null ? null : $prevNew - (int) $prevClosed),
            ],
            charts: [
                $this->statusHistoryChart($range),
                new ChartSeries('new_vs_closed', 'New vs closed tenants', ChartSeries::BAR, KpiValue::COUNT, [
                    ['key' => 'new', 'label' => 'New', 'points' => TimeSeries::points($new, true)],
                    ['key' => 'closed', 'label' => 'Closed', 'points' => TimeSeries::points($closed === [] ? array_fill_keys($range->buckets(), '0') : $closed, true)],
                ], $range->interval),
            ],
            tables: [
                $this->tenantsByPlan(),
                $this->topTenantsByStorage(),
                $this->topTenantsByOrders($range),
            ],
        );
    }

    /**
     * @return list<Alert>
     */
    public function alerts(): array
    {
        $failed = $this->tenantsTable()->where('status', TenantStatus::ProvisioningFailed->value)->count();

        return $failed === 0 ? [] : [
            new Alert('provisioning_failed', Alert::CRITICAL, "{$failed} tenant(s) failed provisioning.", $failed, '/admin/tenants?status=provisioning_failed'),
        ];
    }

    /**
     * The tenants list KPI strip (§22.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range): array
    {
        $counts = $this->statusCounts();

        return [
            KpiValue::count('total', 'Total', array_sum($counts), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('active', 'Active', $counts['active'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('trialing', 'Trialing', $this->trialTenants(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('pending', 'Awaiting payment and provisioning', $counts['awaiting_payment'] + $counts['provisioning'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('suspended', 'Suspended', $counts['suspended'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('closed', 'Closed', $counts['closed'], $range, null, KpiValue::NEUTRAL),
        ];
    }

    /**
     * Tenants per status, purged excluded, every status present.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(array_values(array_diff(
            array_map(static fn (TenantStatus $s): string => $s->value, TenantStatus::cases()),
            [TenantStatus::Purged->value],
        )), 0);

        $rows = $this->tenantsTable()
            ->where('status', '!=', TenantStatus::Purged->value)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $count) {
            $counts[(string) $status] = (int) $count;
        }

        return $counts;
    }

    /**
     * Active tenants whose current subscription is trialing.
     */
    public function trialTenants(): int
    {
        return $this->tenantsTable()
            ->where('status', TenantStatus::Active->value)
            ->whereExists(static fn (Builder $q) => $q->from('subscriptions')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->where('subscriptions.status', SubscriptionStatus::Trialing->value))
            ->count();
    }

    /**
     * Tenants whose current subscription is cancelled: they have a
     * cancelled subscription and none that is not.
     */
    public function cancelledTenants(): int
    {
        return $this->tenantsTable()
            ->where('status', '!=', TenantStatus::Purged->value)
            ->whereExists(static fn (Builder $q) => $q->from('subscriptions')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->where('subscriptions.status', SubscriptionStatus::Cancelled->value))
            ->whereNotExists(static fn (Builder $q) => $q->from('subscriptions')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')
                ->whereNotIn('subscriptions.status', [SubscriptionStatus::Cancelled->value, SubscriptionStatus::Incomplete->value]))
            ->count();
    }

    /**
     * @return array<string, string>
     */
    private function newTenantSeries(DateRange $range): array
    {
        return TimeSeries::aggregate($this->tenantsTable(), 'created_at', $range, 'COUNT(*)')['']
            ?? array_fill_keys($range->buckets(), '0.0000');
    }

    /**
     * The snapshot total of the given statuses on the comparison end date,
     * or null without a snapshot or a comparison.
     *
     * @param  list<string>  $statuses
     */
    private function historyCount(DateRange $range, array $statuses): ?int
    {
        if ($range->comparisonTo === null) {
            return null;
        }

        $values = PlatformDailyMetric::valuesOn('tenants_by_status', $range->comparisonTo);

        return $values === null ? null : (int) array_sum(array_map('floatval', array_intersect_key($values, array_flip($statuses))));
    }

    private function statusHistoryChart(DateRange $range): ChartSeries
    {
        $history = PlatformDailyMetric::history('tenants_by_status', $range->from, $range->to);
        $series = [];

        foreach ($history as $status => $days) {
            $points = [];

            // A stock metric's bucket value is its last day in the bucket.
            foreach ($days as $date => $value) {
                $points[$range->interval === 'hour' ? $date : $range->bucketOf(CarbonImmutable::parse($date, $range->timezone))] = (int) $value;
            }

            $series[] = [
                'key' => $status,
                'label' => ucwords(str_replace('_', ' ', $status)),
                'points' => array_map(static fn (string $x, int $y): array => ['x' => $x, 'y' => $y], array_keys($points), array_values($points)),
            ];
        }

        return new ChartSeries('tenants_by_status', 'Tenants by status', ChartSeries::LINE, KpiValue::COUNT, $series, $range->interval === 'hour' ? 'day' : $range->interval);
    }

    private function latestSignUps(): TableBlock
    {
        $rows = $this->tenantsTable()
            ->where('status', '!=', TenantStatus::Purged->value)
            ->orderByDesc('created_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['id', 'name', 'status', 'created_at'])
            ->map(static fn ($t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status,
                'created_at' => CarbonImmutable::parse($t->created_at, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('latest_sign_ups', 'Latest sign-ups', [
            ['key' => 'name', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'created_at', 'label' => 'Signed up', 'format' => 'datetime'],
        ], $rows, '/admin/tenants');
    }

    private function tenantsByPlan(): TableBlock
    {
        $rows = DB::connection('landlord')->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->groupBy('plans.id', 'plans.name', 'plans.slug')
            ->orderByDesc('tenants')
            ->limit(TableBlock::MAX_ROWS)
            ->selectRaw('plans.id, plans.name, plans.slug, COUNT(DISTINCT subscriptions.tenant_id) as tenants')
            ->get()
            ->map(static fn ($r): array => ['plan_id' => (int) $r->id, 'plan' => $r->name, 'slug' => $r->slug, 'tenants' => (int) $r->tenants])
            ->all();

        return new TableBlock('tenants_by_plan', 'Tenants by plan', [
            ['key' => 'plan', 'label' => 'Plan', 'format' => 'text'],
            ['key' => 'tenants', 'label' => 'Tenants', 'format' => 'count'],
        ], $rows, '/admin/tenants');
    }

    private function topTenantsByStorage(): TableBlock
    {
        $latest = DB::connection('landlord')->table('tenant_usage_snapshots')
            ->where('date', '>=', now()->subDays(self::SNAPSHOT_FRESH_DAYS)->toDateString())
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, MAX(date) as latest_date');

        $rows = DB::connection('landlord')->table('tenant_usage_snapshots as s')
            ->joinSub($latest, 'l', static fn ($j) => $j->on('l.tenant_id', '=', 's.tenant_id')->on('l.latest_date', '=', 's.date'))
            ->join('tenants', 'tenants.id', '=', 's.tenant_id')
            ->selectRaw("s.tenant_id, tenants.name, CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.usage, '$.max_storage_mb')), '0') AS UNSIGNED) as storage_mb")
            ->orderByDesc('storage_mb')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => ['tenant_id' => $r->tenant_id, 'name' => $r->name, 'storage_mb' => (int) $r->storage_mb])
            ->all();

        return new TableBlock('top_tenants_by_storage', 'Top tenants by storage', [
            ['key' => 'name', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'storage_mb', 'label' => 'Storage (MB)', 'format' => 'count'],
        ], $rows, '/admin/tenants');
    }

    private function topTenantsByOrders(DateRange $range): TableBlock
    {
        $rows = DB::connection('landlord')->table('tenant_usage_snapshots as s')
            ->join('tenants', 'tenants.id', '=', 's.tenant_id')
            ->whereBetween('s.date', [$range->from->toDateString(), $range->to->toDateString()])
            ->groupBy('s.tenant_id', 'tenants.name')
            ->havingRaw('SUM(s.orders_count) > 0')
            ->selectRaw('s.tenant_id, tenants.name, SUM(s.orders_count) as orders')
            ->orderByDesc('orders')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => ['tenant_id' => $r->tenant_id, 'name' => $r->name, 'orders' => (int) $r->orders])
            ->all();

        return new TableBlock('top_tenants_by_orders', 'Top tenants by orders', [
            ['key' => 'name', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => 'count'],
        ], $rows, '/admin/tenants');
    }

    private function tenantsTable(): Builder
    {
        return DB::connection('landlord')->table('tenants');
    }
}
