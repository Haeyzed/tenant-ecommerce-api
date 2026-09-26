<?php

declare(strict_types=1);

namespace App\Modules\Billing\Metrics;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Dashboard\Models\PlatformDailyMetric;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Subscription and recurring-revenue figures (spec §22.5). MRR comes only
 * from the append-only subscription_mrr_movements ledger, which holds live
 * subscriptions only, so test-mode activity never reaches these figures.
 */
final readonly class SubscriptionMetrics
{
    private const array MOVEMENT_TYPES = ['new', 'expansion', 'reactivation', 'contraction', 'churn'];

    public function __construct(private PlatformSettingsService $settings) {}

    public function overview(DateRange $range): SectionResult
    {
        $totals = $this->settings->reportingTotals();
        $comparison = $range->comparison();
        [$mrrPoints, $mrrNow] = $this->mrrSeries($range);

        $netNew = TimeSeries::total($this->movements(), 'occurred_at', $range, 'SUM(mrr_delta)', 'currency_code');
        $prevNetNew = $comparison === null ? null : TimeSeries::total($this->movements(), 'occurred_at', $comparison, 'SUM(mrr_delta)', 'currency_code');
        $conversion = $this->trialConversion($range);

        return new SectionResult(
            kpis: [
                KpiValue::count('paying_tenants', 'Paying tenants', $this->payingTenants($range->endUtc()), $range,
                    $comparison === null ? null : $this->payingTenants($comparison->endUtc())),
                ...$totals->kpis('mrr', 'MRR', $mrrNow, $range, $comparison === null ? null : $this->mrrAsOf($comparison->endUtc()),
                    KpiValue::UP_IS_GOOD, array_map(static fn (array $p): array => TimeSeries::sparkline($p), $mrrPoints)),
                ...$totals->kpis('net_new_mrr', 'Net new MRR', $netNew, $range, $prevNetNew),
                KpiValue::rate('trial_conversion_rate', 'Trial conversion rate', $conversion[0], $conversion[1], $range,
                    $comparison === null ? null : $this->trialConversion($comparison)),
            ],
            charts: $this->mrrCharts($range, $mrrPoints),
        );
    }

    /**
     * Logo churn for the tenants section: tenants with a churn movement in
     * the range over paying tenants at the range start.
     */
    public function tenantChurn(DateRange $range): SectionResult
    {
        $comparison = $range->comparison();
        [$churned, $payingAtStart] = $this->logoChurn($range);

        return new SectionResult(kpis: [
            KpiValue::rate('logo_churn_rate', 'Logo churn rate', $churned, $payingAtStart, $range,
                $comparison === null ? null : $this->logoChurn($comparison), KpiValue::DOWN_IS_GOOD),
        ]);
    }

    public function subscriptions(DateRange $range): SectionResult
    {
        $comparison = $range->comparison();
        $status = $this->statusCounts();
        $history = $range->comparisonTo === null ? null : PlatformDailyMetric::valuesOn('subscriptions_by_status', $range->comparisonTo);
        $stock = static fn (string $s): ?int => $history === null ? null : (int) ($history[$s] ?? 0);

        $conversion = $this->trialConversion($range);
        $renewals = TimeSeries::aggregate($this->renewalCharges(), 'paid_at', $range, 'COUNT(*)')[''] ?? array_fill_keys($range->buckets(), '0');
        [$upgrades, $downgrades] = $this->planChanges($range);
        $previousChanges = $comparison === null ? null : $this->planChanges($comparison);
        $cancellations = TimeSeries::aggregate($this->subscriptionsTable(), 'cancelled_at', $range, 'COUNT(*)')[''] ?? array_fill_keys($range->buckets(), '0');

        return new SectionResult(
            kpis: [
                KpiValue::count('active_subscriptions', 'Active subscriptions', $status['active'], $range, $stock('active')),
                KpiValue::count('trialing_subscriptions', 'Trialing subscriptions', $status['trialing'], $range, $stock('trialing'), KpiValue::NEUTRAL),
                KpiValue::count('past_due_subscriptions', 'Past due subscriptions', $status['past_due'], $range, $stock('past_due'), KpiValue::DOWN_IS_GOOD),
                KpiValue::rate('trial_conversion_rate', 'Trial conversion rate', $conversion[0], $conversion[1], $range, $comparison === null ? null : $this->trialConversion($comparison)),
                KpiValue::count('renewals', 'Renewals', (int) TimeSeries::sum($renewals), $range,
                    $comparison === null ? null : (int) (TimeSeries::total($this->renewalCharges(), 'paid_at', $comparison, 'COUNT(*)')[''] ?? 0),
                    KpiValue::UP_IS_GOOD, TimeSeries::sparkline($renewals, true)),
                KpiValue::count('upgrades', 'Upgrades', $upgrades, $range, $previousChanges[0] ?? null),
                KpiValue::count('downgrades', 'Downgrades', $downgrades, $range, $previousChanges[1] ?? null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('cancellations', 'Cancellations', (int) TimeSeries::sum($cancellations), $range,
                    $comparison === null ? null : (int) (TimeSeries::total($this->subscriptionsTable(), 'cancelled_at', $comparison, 'COUNT(*)')[''] ?? 0),
                    KpiValue::DOWN_IS_GOOD, TimeSeries::sparkline($cancellations, true)),
            ],
            charts: [
                ...$this->movementCharts($range),
                new ChartSeries('subscriptions_by_status', 'Subscriptions by status', ChartSeries::DONUT, KpiValue::COUNT, [[
                    'key' => 'status',
                    'label' => 'Subscriptions',
                    'points' => array_map(static fn (string $s, int $c): array => ['x' => $s, 'y' => $c], array_keys($status), array_values($status)),
                ]]),
            ],
            tables: [$this->upcomingRenewals(), $this->trialsEndingSoon()],
        );
    }

    /**
     * MRR, ARR and ARPA for the revenue section.
     */
    public function recurring(DateRange $range): SectionResult
    {
        $totals = $this->settings->reportingTotals();
        $comparison = $range->comparison();
        $mrr = $this->mrrAsOf($range->endUtc());
        $prevMrr = $comparison === null ? null : $this->mrrAsOf($comparison->endUtc());
        $times12 = static fn (?array $v): ?array => $v === null ? null : array_map(static fn (string $a): string => bcmul($a, '12', 4), $v);

        $arpa = $this->arpa($mrr, $this->payingTenantsByCurrency($range->endUtc()));
        $prevArpa = $comparison === null || $prevMrr === null ? null : $this->arpa($prevMrr, $this->payingTenantsByCurrency($comparison->endUtc()));

        return new SectionResult(kpis: [
            ...$totals->kpis('mrr', 'MRR', $mrr, $range, $prevMrr),
            ...$totals->kpis('arr', 'ARR', $times12($mrr) ?? [], $range, $times12($prevMrr)),
            // ARPA is an average, so it is never combined across currencies.
            ...array_map(
                static fn (string $currency): KpiValue => KpiValue::money('arpa', 'ARPA', $arpa[$currency], $currency, $range, $prevArpa === null ? null : ($prevArpa[$currency] ?? '0')),
                array_keys($arpa),
            ),
        ]);
    }

    /**
     * @return list<Alert>
     */
    public function alerts(): array
    {
        $grace = (int) $this->settings->get('past_due_grace_days', 7);
        $count = $this->subscriptionsTable()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->where('past_due_at', '<', now()->subDays($grace))
            ->count();

        return $count === 0 ? [] : [
            new Alert('past_due_beyond_grace', Alert::CRITICAL, "{$count} subscription(s) are past due beyond the {$grace}-day grace period.", $count, '/admin/subscriptions?status=past_due'),
        ];
    }

    /**
     * The subscriptions list KPI strip (§22.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range): array
    {
        $status = $this->statusCounts();
        $cancelling = $this->subscriptionsTable()
            ->where('status', SubscriptionStatus::Cancelled->value)
            ->where('ends_at', '>', now())
            ->count();

        return [
            KpiValue::count('active', 'Active', $status['active'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('trialing', 'Trialing', $status['trialing'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('past_due', 'Past due', $status['past_due'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('cancelling', 'Cancelling', $cancelling, $range, null, KpiValue::NEUTRAL),
            ...$this->settings->reportingTotals()->kpis('mrr', 'MRR', $this->mrrAsOf(CarbonImmutable::now()), $range),
        ];
    }

    /**
     * Σ mrr_delta of all movements up to the instant, per currency (§14.10).
     *
     * @return array<string, string>
     */
    public function mrrAsOf(CarbonInterface $at): array
    {
        return $this->movements()
            ->where('occurred_at', '<=', $at)
            ->groupBy('currency_code')
            ->selectRaw('currency_code, SUM(mrr_delta) as mrr')
            ->pluck('mrr', 'currency_code')
            ->map(static fn ($v): string => bcadd((string) $v, '0', 4))
            ->all();
    }

    /**
     * Tenants whose MRR at the instant is greater than zero.
     */
    public function payingTenants(CarbonInterface $at): int
    {
        return DB::connection('landlord')->query()->fromSub(
            $this->movements()->where('occurred_at', '<=', $at)->groupBy('tenant_id')->havingRaw('SUM(mrr_delta) > 0')->select('tenant_id'),
            'paying',
        )->count();
    }

    /**
     * Subscriptions by status, every status present.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(array_map(static fn (SubscriptionStatus $s): string => $s->value, SubscriptionStatus::cases()), 0);

        foreach ($this->subscriptionsTable()->groupBy('status')->selectRaw('status, COUNT(*) as aggregate')->pluck('aggregate', 'status') as $status => $count) {
            $counts[(string) $status] = (int) $count;
        }

        return $counts;
    }

    /**
     * @return array<string, int> currency => paying tenants
     */
    private function payingTenantsByCurrency(CarbonInterface $at): array
    {
        return DB::connection('landlord')->query()->fromSub(
            $this->movements()->where('occurred_at', '<=', $at)->groupBy('tenant_id', 'currency_code')->havingRaw('SUM(mrr_delta) > 0')->select('tenant_id', 'currency_code'),
            'paying',
        )->groupBy('currency_code')->selectRaw('currency_code, COUNT(*) as tenants')->pluck('tenants', 'currency_code')
            ->map(static fn ($v): int => (int) $v)->all();
    }

    /**
     * @param  array<string, string>  $mrr
     * @param  array<string, int>  $paying
     * @return array<string, string>
     */
    private function arpa(array $mrr, array $paying): array
    {
        $arpa = [];

        foreach ($mrr as $currency => $amount) {
            if (($paying[$currency] ?? 0) > 0) {
                $arpa[$currency] = bcdiv($amount, (string) $paying[$currency], 4);
            }
        }

        return $arpa;
    }

    /**
     * MRR per currency at the end of each bucket, and at the range end.
     *
     * @return array{0: array<string, array<string, string>>, 1: array<string, string>}
     */
    private function mrrSeries(DateRange $range): array
    {
        $opening = $this->mrrAsOf($range->startUtc()->subSecond());
        $deltas = TimeSeries::aggregate($this->movements(), 'occurred_at', $range, 'SUM(mrr_delta)', 'currency_code');
        $series = [];
        $closing = [];

        foreach (array_unique([...array_keys($opening), ...array_keys($deltas)]) as $currency) {
            $running = $opening[$currency] ?? '0.0000';
            $points = [];

            foreach ($deltas[$currency] ?? array_fill_keys($range->buckets(), '0') as $bucket => $delta) {
                $running = bcadd($running, $delta, 4);
                $points[$bucket] = $running;
            }

            $series[$currency] = $points;
            $closing[$currency] = $running;
        }

        return [$series, $closing];
    }

    /**
     * @param  array<string, array<string, string>>  $points
     * @return list<ChartSeries>
     */
    private function mrrCharts(DateRange $range, array $points): array
    {
        $charts = [];

        foreach ($points === [] ? [$this->settings->reportingTotals()->reportingCurrency() => array_fill_keys($range->buckets(), '0.0000')] : $points as $currency => $series) {
            $charts[] = new ChartSeries('mrr_over_time', 'MRR over time', ChartSeries::LINE, KpiValue::MONEY, [
                ['key' => 'mrr', 'label' => 'MRR', 'points' => TimeSeries::points($series)],
            ], $range->interval, $currency);
        }

        return $charts;
    }

    /**
     * Stacked MRR movement per interval: new, expansion, reactivation as
     * positive amounts; contraction and churn as negative amounts.
     *
     * @return list<ChartSeries>
     */
    private function movementCharts(DateRange $range): array
    {
        $byCurrency = [];

        foreach (self::MOVEMENT_TYPES as $type) {
            foreach (TimeSeries::aggregate((clone $this->movements())->where('type', $type), 'occurred_at', $range, 'SUM(mrr_delta)', 'currency_code') as $currency => $points) {
                $byCurrency[$currency][$type] = $points;
            }
        }

        if ($byCurrency === []) {
            $byCurrency[$this->settings->reportingTotals()->reportingCurrency()] = [];
        }

        $charts = [];

        foreach ($byCurrency as $currency => $types) {
            $charts[] = new ChartSeries('mrr_movement', 'MRR movement', ChartSeries::STACKED_BAR, KpiValue::MONEY, array_map(
                static fn (string $type): array => [
                    'key' => $type,
                    'label' => ucfirst($type),
                    'points' => TimeSeries::points($types[$type] ?? array_fill_keys($range->buckets(), '0.0000')),
                ],
                self::MOVEMENT_TYPES,
            ), $range->interval, $currency);
        }

        return $charts;
    }

    /**
     * Of live trials whose trial ended in the range, how many were
     * followed by the tenant's first paid charge within the past-due grace
     * period (§22.5).
     *
     * @return array{0: string, 1: string} numerator, denominator
     */
    private function trialConversion(DateRange $range): array
    {
        $grace = (int) $this->settings->get('past_due_grace_days', 7);
        $trials = $this->subscriptionsTable()
            ->where('gateway_mode', 'live')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$range->startUtc(), $range->endUtc()]);

        $converted = (clone $trials)->whereExists(static fn (Builder $q) => $q->from('payment_transactions')
            ->whereColumn('payment_transactions.tenant_id', 'subscriptions.tenant_id')
            ->where('payment_transactions.type', PaymentTransaction::CHARGE)
            ->where('payment_transactions.mode', 'live')
            ->where('payment_transactions.status', PaymentTransaction::SUCCESSFUL)
            ->where('payment_transactions.is_first_paid_charge', true)
            ->whereRaw('payment_transactions.paid_at <= DATE_ADD(subscriptions.trial_ends_at, INTERVAL ? DAY)', [$grace]));

        return [(string) $converted->count(), (string) $trials->count()];
    }

    /**
     * @return array{0: string, 1: string} churned tenants, paying tenants at the start
     */
    private function logoChurn(DateRange $range): array
    {
        $churned = (clone $this->movements())
            ->where('type', 'churn')
            ->whereBetween('occurred_at', [$range->startUtc(), $range->endUtc()])
            ->distinct()
            ->count('tenant_id');

        return [(string) $churned, (string) $this->payingTenants($range->startUtc()->subSecond())];
    }

    /**
     * Upgrades and downgrades: expansion and contraction movements caused
     * by a plan change.
     *
     * @return array{0: int, 1: int}
     */
    public function planChanges(DateRange $range): array
    {
        $counts = (clone $this->movements())
            ->where('reason', 'plan_change')
            ->whereIn('type', ['expansion', 'contraction'])
            ->whereBetween('occurred_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as aggregate')
            ->pluck('aggregate', 'type');

        return [(int) ($counts['expansion'] ?? 0), (int) ($counts['contraction'] ?? 0)];
    }

    private function upcomingRenewals(): TableBlock
    {
        $rows = $this->subscriptionsTable()
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->where('subscriptions.renews_at', '>=', now())
            ->whereNull('subscriptions.scheduled_plan_id')
            ->orderBy('subscriptions.renews_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['subscriptions.id', 'subscriptions.tenant_id', 'tenants.name as tenant', 'plans.name as plan', 'subscriptions.renews_at'])
            ->map(static fn ($r): array => [
                'subscription_id' => (int) $r->id,
                'tenant_id' => $r->tenant_id,
                'tenant' => $r->tenant,
                'plan' => $r->plan,
                'renews_at' => CarbonImmutable::parse($r->renews_at, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('upcoming_renewals', 'Upcoming renewals', [
            ['key' => 'tenant', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'plan', 'label' => 'Plan', 'format' => 'text'],
            ['key' => 'renews_at', 'label' => 'Renews', 'format' => 'datetime'],
        ], $rows, '/admin/subscriptions?status=active');
    }

    private function trialsEndingSoon(): TableBlock
    {
        $rows = $this->subscriptionsTable()
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', SubscriptionStatus::Trialing->value)
            ->whereBetween('subscriptions.trial_ends_at', [now(), now()->addDays(7)])
            ->orderBy('subscriptions.trial_ends_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['subscriptions.id', 'subscriptions.tenant_id', 'tenants.name as tenant', 'plans.name as plan', 'subscriptions.trial_ends_at'])
            ->map(static fn ($r): array => [
                'subscription_id' => (int) $r->id,
                'tenant_id' => $r->tenant_id,
                'tenant' => $r->tenant,
                'plan' => $r->plan,
                'trial_ends_at' => CarbonImmutable::parse($r->trial_ends_at, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('trials_ending_soon', 'Trials ending in 7 days', [
            ['key' => 'tenant', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'plan', 'label' => 'Plan', 'format' => 'text'],
            ['key' => 'trial_ends_at', 'label' => 'Trial ends', 'format' => 'datetime'],
        ], $rows, '/admin/subscriptions?status=trialing');
    }

    private function renewalCharges(): Builder
    {
        return DB::connection('landlord')->table('payment_transactions')
            ->where('type', PaymentTransaction::CHARGE)
            ->where('mode', 'live')
            ->where('status', PaymentTransaction::SUCCESSFUL)
            ->where('is_first_paid_charge', false)
            ->where('amount', '>', 0);
    }

    private function movements(): Builder
    {
        return DB::connection('landlord')->table('subscription_mrr_movements');
    }

    private function subscriptionsTable(): Builder
    {
        return DB::connection('landlord')->table('subscriptions');
    }
}
