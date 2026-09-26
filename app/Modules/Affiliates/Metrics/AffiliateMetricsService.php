<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Metrics;

use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Affiliate figures (spec §21A.8, §22.5 "Affiliates"): the affiliate's own
 * portal KPIs, the landlord "affiliates" dashboard section and the admin
 * list KPI strips. One implementation serves all three; a null affiliate
 * means "across all affiliates".
 */
final readonly class AffiliateMetricsService
{
    public function __construct(private PlatformSettingsService $settings) {}

    /**
     * The affiliate portal dashboard (§21A.8).
     *
     * @return list<KpiValue>
     */
    public function portal(Affiliate $affiliate, DateRange $range): array
    {
        return [...$this->funnelKpis($range, $affiliate->id), ...$this->commissionKpis($range, $affiliate->id, true)];
    }

    /**
     * The landlord "affiliates" dashboard section (§22.6).
     */
    public function section(DateRange $range): SectionResult
    {
        $totals = $this->settings->reportingTotals();
        $comparison = $range->comparison();
        $status = $this->statusCounts();

        $attributed = $this->attributedRevenue($range);
        $cost = $this->commissionCost($range);

        return new SectionResult(
            kpis: [
                KpiValue::count('active_affiliates', 'Active affiliates', $status['approved'], $range, null, KpiValue::UP_IS_GOOD),
                KpiValue::count('pending_approvals', 'Pending approvals', $this->pendingApprovals(), $range, null, KpiValue::NEUTRAL),
                ...$this->funnelKpis($range, null),
                ...$this->commissionKpis($range, null, false),
                ...$totals->kpis('affiliate_attributed_revenue', 'Affiliate-attributed revenue', $attributed, $range,
                    $comparison === null ? null : $this->attributedRevenue($comparison)),
                ...$totals->kpis('commission_cost', 'Commission cost', $cost, $range,
                    $comparison === null ? null : $this->commissionCost($comparison), KpiValue::NEUTRAL),
                ...$totals->kpis('outstanding_liability', 'Outstanding payout liability', $this->liability(null), $range, null, KpiValue::NEUTRAL,
                    [], 'Pending (not yet due) and payable commissions'),
            ],
            charts: [$this->funnelChart($range), ...$this->commissionCharts($range)],
            tables: [$this->topAffiliates($range), $this->latestApplications()],
        );
    }

    /**
     * @return list<Alert>
     */
    public function alerts(): array
    {
        $alerts = [];
        $eligible = AffiliateCommission::query()->eligible()->count();
        $flagged = AffiliateReferral::query()->where('requires_review', true)->whereIn('status', [AffiliateReferral::REGISTERED, AffiliateReferral::CONVERTED])->count();
        $payouts = AffiliatePayout::query()->where('status', AffiliatePayout::PENDING)->count();

        if ($eligible > 0) {
            $alerts[] = new Alert('commissions_awaiting_approval', Alert::WARNING, "{$eligible} commission(s) have passed their hold and await approval.", $eligible, '/admin/affiliate-commissions?eligible_only=1');
        }

        if ($flagged > 0) {
            $alerts[] = new Alert('flagged_referrals', Alert::WARNING, "{$flagged} flagged referral(s) await review.", $flagged, '/admin/affiliate-referrals?requires_review=1');
        }

        if ($payouts > 0) {
            $alerts[] = new Alert('payouts_awaiting_payment', Alert::INFO, "{$payouts} affiliate payout(s) await payment.", $payouts, '/admin/affiliate-payouts?status=pending');
        }

        return $alerts;
    }

    /**
     * The affiliates list KPI strip (§22.4).
     *
     * @return list<KpiValue>
     */
    public function affiliatesStrip(DateRange $range): array
    {
        $status = $this->statusCounts();

        return [
            KpiValue::count('approved', 'Approved', $status['approved'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('pending_review', 'Pending review', $this->pendingApprovals(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('suspended', 'Suspended', $status['suspended'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('flagged_referrals', 'Flagged referrals awaiting review', AffiliateReferral::query()
                ->where('requires_review', true)->whereIn('status', [AffiliateReferral::REGISTERED, AffiliateReferral::CONVERTED])->count(), $range, null, KpiValue::NEUTRAL),
        ];
    }

    /**
     * The affiliate-commissions list KPI strip (§22.4).
     *
     * @return list<KpiValue>
     */
    public function commissionsStrip(DateRange $range): array
    {
        return [
            KpiValue::count('eligible', 'Eligible for approval', AffiliateCommission::query()->eligible()->count(), $range, null, KpiValue::NEUTRAL),
            ...$this->commissionKpis($range, null, false),
        ];
    }

    /**
     * The affiliate-payouts list KPI strip (§22.4).
     *
     * @return list<KpiValue>
     */
    public function payoutsStrip(DateRange $range): array
    {
        $comparison = $range->comparison();
        $totals = $this->settings->reportingTotals();
        $paid = fn (DateRange $r): array => $this->sumByCurrency(DB::connection('landlord')->table('affiliate_payouts')->where('status', AffiliatePayout::PAID), 'paid_at', $r);
        $failed = static fn (DateRange $r): int => (int) (TimeSeries::total(DB::connection('landlord')->table('affiliate_payouts')->whereNotNull('failed_at'), 'failed_at', $r, 'COUNT(*)')[''] ?? 0);

        return [
            KpiValue::count('pending_payouts', 'Pending payouts', AffiliatePayout::query()->where('status', AffiliatePayout::PENDING)->count(), $range, null, KpiValue::NEUTRAL),
            ...$totals->kpis('paid', 'Paid', $paid($range), $range, $comparison === null ? null : $paid($comparison), KpiValue::NEUTRAL),
            KpiValue::count('failed', 'Failed', $failed($range), $range, $comparison === null ? null : $failed($comparison), KpiValue::DOWN_IS_GOOD),
            ...$totals->kpis('outstanding_liability', 'Outstanding liability', $this->liability(null), $range, null, KpiValue::NEUTRAL),
        ];
    }

    /**
     * Clicks, sign-ups, trials, paid conversions and the cohort
     * conversion rate.
     *
     * @return list<KpiValue>
     */
    private function funnelKpis(DateRange $range, ?int $affiliateId): array
    {
        $comparison = $range->comparison();
        $clicks = TimeSeries::aggregate($this->scoped('affiliate_clicks', $affiliateId), 'created_at', $range, 'COUNT(*)')[''] ?? array_fill_keys($range->buckets(), '0');
        $signUps = TimeSeries::aggregate($this->scoped('affiliate_referrals', $affiliateId), 'attributed_at', $range, 'COUNT(*)')[''] ?? array_fill_keys($range->buckets(), '0');
        $count = fn (string $table, string $column, DateRange $r, ?callable $filter = null): int => (int) (TimeSeries::total(
            $filter === null ? $this->scoped($table, $affiliateId) : $filter($this->scoped($table, $affiliateId)), $column, $r, 'COUNT(*)',
        )[''] ?? 0);
        $paidFilter = static fn (Builder $q): Builder => $q->where('type', AffiliateCommission::COMMISSION);

        [$converted, $cohort] = $this->cohort($range, $affiliateId);

        return [
            KpiValue::count('clicks', 'Clicks', (int) TimeSeries::sum($clicks), $range,
                $comparison === null ? null : $count('affiliate_clicks', 'created_at', $comparison), KpiValue::UP_IS_GOOD, TimeSeries::sparkline($clicks, true)),
            KpiValue::count('sign_ups', 'Sign-ups', (int) TimeSeries::sum($signUps), $range,
                $comparison === null ? null : $count('affiliate_referrals', 'attributed_at', $comparison), KpiValue::UP_IS_GOOD, TimeSeries::sparkline($signUps, true)),
            KpiValue::count('trials', 'Trials', $this->trials($range, $affiliateId), $range,
                $comparison === null ? null : $this->trials($comparison, $affiliateId)),
            KpiValue::count('paid_conversions', 'Paid conversions', $count('affiliate_commissions', 'created_at', $range, $paidFilter), $range,
                $comparison === null ? null : $count('affiliate_commissions', 'created_at', $comparison, $paidFilter)),
            KpiValue::rate('conversion_rate', 'Conversion rate', (string) $converted, (string) $cohort, $range,
                $comparison === null ? null : array_map('strval', $this->cohort($comparison, $affiliateId))),
        ];
    }

    /**
     * Pending, payable, paid (in range), reversed (in range) and, for the
     * portal, lifetime earnings, per currency.
     *
     * @return list<KpiValue>
     */
    private function commissionKpis(DateRange $range, ?int $affiliateId, bool $withLifetime): array
    {
        $totals = $this->settings->reportingTotals();
        $comparison = $range->comparison();
        $commissions = fn (): Builder => $this->scoped('affiliate_commissions', $affiliateId);

        $pending = $this->sumByCurrency($commissions()->where('status', AffiliateCommission::PENDING));
        $payable = $this->sumByCurrency($commissions()->where('status', AffiliateCommission::APPROVED)->whereNull('affiliate_payout_id'));
        $paid = fn (DateRange $r): array => $this->sumByCurrency($commissions()->where('status', AffiliateCommission::PAID), 'paid_at', $r);
        $reversed = fn (DateRange $r): array => $this->reversed($commissions, $r);

        $kpis = [
            ...$totals->kpis('pending_commission', 'Pending commission', $pending, $range, null, KpiValue::NEUTRAL),
            ...$totals->kpis('payable_commission', 'Approved (payable) commission', $payable, $range, null, KpiValue::NEUTRAL),
            ...$totals->kpis('paid_commission', 'Paid commission', $paid($range), $range, $comparison === null ? null : $paid($comparison)),
            ...$totals->kpis('reversed_commission', 'Reversed commission', $reversed($range), $range,
                $comparison === null ? null : $reversed($comparison), KpiValue::DOWN_IS_GOOD),
        ];

        if ($withLifetime) {
            array_push($kpis, ...$totals->kpis('lifetime_earnings', 'Lifetime earnings', $this->sumByCurrency($commissions()->where('status', AffiliateCommission::PAID)), $range));
        }

        return $kpis;
    }

    /**
     * Σ original_amount or amount of commissions reversed in the range,
     * plus Σ clawbacks created in the range, as positive amounts.
     *
     * @param  callable(): Builder  $commissions
     * @return array<string, string>
     */
    private function reversed(callable $commissions, DateRange $range): array
    {
        $reversed = TimeSeries::total($commissions()->where('status', AffiliateCommission::REVERSED), 'reversed_at', $range, 'SUM(COALESCE(original_amount, amount))', 'currency_code');
        $clawbacks = TimeSeries::total($commissions()->where('type', AffiliateCommission::CLAWBACK), 'created_at', $range, 'SUM(ABS(amount))', 'currency_code');

        foreach ($clawbacks as $currency => $amount) {
            $reversed[$currency] = bcadd($reversed[$currency] ?? '0', $amount, 4);
        }

        return $reversed;
    }

    /**
     * Net subscription revenue in the range from referred tenants (all
     * their live charges, not only the first).
     *
     * @return array<string, string>
     */
    private function attributedRevenue(DateRange $range): array
    {
        $query = DB::connection('landlord')->table('payment_transactions')
            ->whereIn('type', [PaymentTransaction::CHARGE, PaymentTransaction::REFUND, PaymentTransaction::CHARGEBACK])
            ->where('mode', 'live')
            ->where('status', PaymentTransaction::SUCCESSFUL)
            ->whereIn('tenant_id', DB::connection('landlord')->table('affiliate_referrals')->select('tenant_id'));

        return TimeSeries::total($query, 'paid_at', $range, 'SUM(amount)', 'currency_code');
    }

    /**
     * Commissions created in the range that are not rejected, minus
     * reversals and clawbacks in the range.
     *
     * @return array<string, string>
     */
    private function commissionCost(DateRange $range): array
    {
        $created = TimeSeries::total(
            DB::connection('landlord')->table('affiliate_commissions')->where('type', AffiliateCommission::COMMISSION)->where('status', '!=', AffiliateCommission::REJECTED),
            'created_at', $range, 'SUM(COALESCE(original_amount, amount))', 'currency_code',
        );

        foreach ($this->reversed(fn (): Builder => $this->scoped('affiliate_commissions', null), $range) as $currency => $amount) {
            $created[$currency] = bcsub($created[$currency] ?? '0', $amount, 4);
        }

        return $created;
    }

    /**
     * Pending plus payable commissions, per currency.
     *
     * @return array<string, string>
     */
    private function liability(?int $affiliateId): array
    {
        return $this->sumByCurrency($this->scoped('affiliate_commissions', $affiliateId)
            ->where(static fn (Builder $q) => $q->where('status', AffiliateCommission::PENDING)
                ->orWhere(static fn (Builder $q) => $q->where('status', AffiliateCommission::APPROVED)->whereNull('affiliate_payout_id'))));
    }

    /**
     * Referrals attributed in the range whose tenant's first subscription
     * started with a trial.
     */
    private function trials(DateRange $range, ?int $affiliateId): int
    {
        return $this->scoped('affiliate_referrals', $affiliateId)
            ->whereBetween('attributed_at', [$range->startUtc(), $range->endUtc()])
            ->whereExists(static fn (Builder $q) => $q->from('subscriptions as s')
                ->whereColumn('s.tenant_id', 'affiliate_referrals.tenant_id')
                ->whereNotNull('s.trial_ends_at')
                ->whereRaw('s.id = (SELECT MIN(s2.id) FROM subscriptions s2 WHERE s2.tenant_id = s.tenant_id)'))
            ->count();
    }

    /**
     * @return array{0: int, 1: int} converted, attributed
     */
    private function cohort(DateRange $range, ?int $affiliateId): array
    {
        $row = $this->scoped('affiliate_referrals', $affiliateId)
            ->whereBetween('attributed_at', [$range->startUtc(), $range->endUtc()])
            ->selectRaw("COUNT(*) as attributed, SUM(CASE WHEN status = 'converted' OR converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted")
            ->first();

        return [(int) ($row->converted ?? 0), (int) ($row->attributed ?? 0)];
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = array_fill_keys(Affiliate::STATUSES, 0);

        foreach (DB::connection('landlord')->table('affiliates')->groupBy('status')->selectRaw('status, COUNT(*) as aggregate')->pluck('aggregate', 'status') as $status => $count) {
            $counts[(string) $status] = (int) $count;
        }

        return $counts;
    }

    private function pendingApprovals(): int
    {
        return Affiliate::query()->where('status', Affiliate::PENDING)->whereNotNull('email_verified_at')->count();
    }

    private function funnelChart(DateRange $range): ChartSeries
    {
        $clicks = (int) (TimeSeries::total($this->scoped('affiliate_clicks', null), 'created_at', $range, 'COUNT(*)')[''] ?? 0);
        $signUps = (int) (TimeSeries::total($this->scoped('affiliate_referrals', null), 'attributed_at', $range, 'COUNT(*)')[''] ?? 0);
        $paid = (int) (TimeSeries::total($this->scoped('affiliate_commissions', null)->where('type', AffiliateCommission::COMMISSION), 'created_at', $range, 'COUNT(*)')[''] ?? 0);

        return new ChartSeries('affiliate_funnel', 'Affiliate funnel', ChartSeries::BAR, KpiValue::COUNT, [[
            'key' => 'funnel',
            'label' => 'Visitors',
            'points' => [
                ['x' => 'clicks', 'y' => $clicks],
                ['x' => 'sign_ups', 'y' => $signUps],
                ['x' => 'trials', 'y' => $this->trials($range, null)],
                ['x' => 'paid', 'y' => $paid],
            ],
        ]]);
    }

    /**
     * @return list<ChartSeries>
     */
    private function commissionCharts(DateRange $range): array
    {
        $series = TimeSeries::aggregate($this->scoped('affiliate_commissions', null)->where('type', AffiliateCommission::COMMISSION), 'created_at', $range, 'SUM(COALESCE(original_amount, amount))', 'currency_code');

        if ($series === []) {
            $series[$this->settings->reportingTotals()->reportingCurrency()] = array_fill_keys($range->buckets(), '0.0000');
        }

        $charts = [];

        foreach ($series as $currency => $points) {
            $charts[] = new ChartSeries('commissions_over_time', 'Commissions', ChartSeries::BAR, KpiValue::MONEY, [
                ['key' => 'commissions', 'label' => 'Commissions created', 'points' => TimeSeries::points($points)],
            ], $range->interval, $currency);
        }

        return $charts;
    }

    private function topAffiliates(DateRange $range): TableBlock
    {
        $rows = DB::connection('landlord')->table('affiliate_commissions as c')
            ->join('affiliates as a', 'a.id', '=', 'c.affiliate_id')
            ->where('c.type', AffiliateCommission::COMMISSION)
            ->whereBetween('c.created_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('a.id', 'a.name', 'a.referral_code')
            ->selectRaw('a.id, a.name, a.referral_code, COUNT(*) as conversions')
            ->orderByDesc('conversions')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => ['affiliate_id' => (int) $r->id, 'name' => $r->name, 'referral_code' => $r->referral_code, 'conversions' => (int) $r->conversions])
            ->all();

        return new TableBlock('top_affiliates', 'Top affiliates by conversions', [
            ['key' => 'name', 'label' => 'Affiliate', 'format' => 'text'],
            ['key' => 'conversions', 'label' => 'Paid conversions', 'format' => 'count'],
        ], $rows, '/admin/affiliates');
    }

    private function latestApplications(): TableBlock
    {
        $rows = Affiliate::query()
            ->where('status', Affiliate::PENDING)
            ->whereNotNull('email_verified_at')
            ->orderByDesc('created_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['id', 'name', 'email', 'created_at'])
            ->map(static fn (Affiliate $a): array => ['affiliate_id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'applied_at' => $a->created_at->toIso8601String()])
            ->all();

        return new TableBlock('latest_applications', 'Latest applications', [
            ['key' => 'name', 'label' => 'Applicant', 'format' => 'text'],
            ['key' => 'email', 'label' => 'Email', 'format' => 'text'],
            ['key' => 'applied_at', 'label' => 'Applied', 'format' => 'datetime'],
        ], $rows, '/admin/affiliates?status=pending');
    }

    /**
     * Σ amount per currency, optionally over a date column in a range.
     *
     * @return array<string, string>
     */
    private function sumByCurrency(Builder $query, ?string $dateColumn = null, ?DateRange $range = null): array
    {
        if ($dateColumn !== null && $range !== null) {
            return TimeSeries::total($query, $dateColumn, $range, 'SUM(amount)', 'currency_code');
        }

        return $query->groupBy('currency_code')->selectRaw('currency_code, SUM(amount) as total')->pluck('total', 'currency_code')
            ->map(static fn ($v): string => bcadd((string) $v, '0', 4))->all();
    }

    private function scoped(string $table, ?int $affiliateId): Builder
    {
        return DB::connection('landlord')->table($table)->when($affiliateId !== null, static fn (Builder $q) => $q->where($table.'.affiliate_id', $affiliateId));
    }
}
