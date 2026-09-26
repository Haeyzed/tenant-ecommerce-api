<?php

declare(strict_types=1);

namespace App\Modules\Billing\Metrics;

use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Settings\Services\PlatformSettingsService;
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
 * Platform revenue (spec §22.5 "Revenue and payments"). Live, successful
 * rows only, dated by paid_at, reported per currency. Refunds and
 * chargebacks are their own negative rows and are reported as positive
 * amounts.
 */
final readonly class RevenueMetrics
{
    private const string GROSS = "SUM(CASE WHEN type = 'charge' THEN amount ELSE 0 END)";

    private const string REFUNDS = "SUM(CASE WHEN type = 'refund' THEN ABS(amount) ELSE 0 END)";

    private const string CHARGEBACKS = "SUM(CASE WHEN type = 'chargeback' THEN ABS(amount) ELSE 0 END)";

    private const string FEES = "SUM(CASE WHEN type = 'charge' THEN COALESCE(fee, 0) ELSE 0 END)";

    public function __construct(private PlatformSettingsService $settings) {}

    public function overview(DateRange $range): SectionResult
    {
        $comparison = $range->comparison();
        $net = $this->netCollected($range);

        return new SectionResult(
            kpis: $this->settings->reportingTotals()->kpis('net_collected_revenue', 'Net collected revenue', $net, $range,
                $comparison === null ? null : $this->netCollected($comparison)),
            tables: [$this->latestPayments()],
        );
    }

    public function revenue(DateRange $range): SectionResult
    {
        $totals = $this->settings->reportingTotals();
        $comparison = $range->comparison();

        $gross = TimeSeries::aggregate($this->moneyRows(), 'paid_at', $range, self::GROSS, 'currency_code');
        $deductions = TimeSeries::aggregate($this->moneyRows(), 'paid_at', $range, self::REFUNDS.' + '.self::CHARGEBACKS, 'currency_code');
        $current = $this->figures($range);
        $previous = $comparison === null ? null : $this->figures($comparison);
        $pick = static fn (?array $figures, string $key): ?array => $figures === null ? null : $figures[$key];

        $feesLabel = $this->hasUnreportedFees($range) ? 'Where reported by the provider' : null;

        return new SectionResult(
            kpis: [
                ...$totals->kpis('gross_revenue', 'Gross subscription revenue', $current['gross'], $range, $pick($previous, 'gross'),
                    KpiValue::UP_IS_GOOD, array_map(static fn (array $p): array => TimeSeries::sparkline($p), $gross)),
                ...$totals->kpis('discounts', 'Discounts', $current['discounts'], $range, $pick($previous, 'discounts'), KpiValue::NEUTRAL),
                ...$totals->kpis('refunds', 'Refunds', $current['refunds'], $range, $pick($previous, 'refunds'), KpiValue::DOWN_IS_GOOD),
                ...$totals->kpis('chargebacks', 'Chargebacks', $current['chargebacks'], $range, $pick($previous, 'chargebacks'), KpiValue::DOWN_IS_GOOD),
                ...$totals->kpis('net_revenue', 'Net subscription revenue', $current['net'], $range, $pick($previous, 'net')),
                ...$totals->kpis('payment_fees', 'Payment fees', $current['fees'], $range, $pick($previous, 'fees'), KpiValue::DOWN_IS_GOOD, [], $feesLabel),
                ...$totals->kpis('net_collected_revenue', 'Net collected revenue', $current['net_collected'], $range, $pick($previous, 'net_collected')),
            ],
            charts: [
                ...$this->revenueCharts($range, $gross, $deductions),
                ...$this->planRevenueCharts($range),
            ],
            tables: [$this->topTenants($range)],
        );
    }

    /**
     * Gross revenue by the plan charged (the plan line of each charge, so
     * a later plan change does not move past revenue).
     */
    public function planRevenue(DateRange $range): SectionResult
    {
        return new SectionResult(charts: $this->planRevenueCharts($range));
    }

    /**
     * @return list<Alert>
     */
    public function alerts(): array
    {
        $count = DB::connection('landlord')->table('payment_transactions')
            ->where('type', PaymentTransaction::CHARGEBACK)
            ->where('mode', 'live')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return $count === 0 ? [] : [
            new Alert('chargebacks_opened', Alert::WARNING, "{$count} chargeback(s) opened in the last 7 days.", $count, '/admin/payment-transactions?type=chargeback'),
        ];
    }

    /**
     * Σ coupon_discount lines of successful live charges paid in the
     * range, as positive amounts per currency.
     *
     * @return array<string, string>
     */
    public function discounts(DateRange $range): array
    {
        return DB::connection('landlord')->table('payment_transactions as pt')
            ->crossJoin(DB::raw("JSON_TABLE(pt.line_items, '$[*]' COLUMNS (line_type VARCHAR(32) PATH '$.type', line_amount DECIMAL(18,4) PATH '$.amount')) as li"))
            ->where('pt.type', PaymentTransaction::CHARGE)
            ->where('pt.mode', 'live')
            ->where('pt.status', PaymentTransaction::SUCCESSFUL)
            ->whereBetween('pt.paid_at', [$range->startUtc(), $range->endUtc()])
            ->where('li.line_type', 'coupon_discount')
            ->groupBy('pt.currency_code')
            ->selectRaw('pt.currency_code, SUM(ABS(li.line_amount)) as discount')
            ->pluck('discount', 'currency_code')
            ->map(static fn ($v): string => bcadd((string) $v, '0', 4))
            ->all();
    }

    /**
     * @return array{gross: array<string, string>, discounts: array<string, string>, refunds: array<string, string>, chargebacks: array<string, string>, net: array<string, string>, fees: array<string, string>, net_collected: array<string, string>}
     */
    private function figures(DateRange $range): array
    {
        $rows = (clone $this->moneyRows())
            ->whereBetween('paid_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('currency_code')
            ->selectRaw('currency_code, '.self::GROSS.' as gross, '.self::REFUNDS.' as refunds, '.self::CHARGEBACKS.' as chargebacks, '.self::FEES.' as fees')
            ->get();

        $figures = ['gross' => [], 'discounts' => $this->discounts($range), 'refunds' => [], 'chargebacks' => [], 'net' => [], 'fees' => [], 'net_collected' => []];

        foreach ($rows as $row) {
            $currency = (string) $row->currency_code;
            $gross = bcadd((string) $row->gross, '0', 4);
            $refunds = bcadd((string) $row->refunds, '0', 4);
            $chargebacks = bcadd((string) $row->chargebacks, '0', 4);
            $fees = bcadd((string) $row->fees, '0', 4);
            $net = bcsub(bcsub($gross, $refunds, 4), $chargebacks, 4);

            $figures['gross'][$currency] = $gross;
            $figures['refunds'][$currency] = $refunds;
            $figures['chargebacks'][$currency] = $chargebacks;
            $figures['fees'][$currency] = $fees;
            $figures['net'][$currency] = $net;
            $figures['net_collected'][$currency] = bcsub($net, $fees, 4);
        }

        return $figures;
    }

    /**
     * @return array<string, string>
     */
    private function netCollected(DateRange $range): array
    {
        return $this->figures($range)['net_collected'];
    }

    private function hasUnreportedFees(DateRange $range): bool
    {
        return DB::connection('landlord')->table('payment_transactions')
            ->where('type', PaymentTransaction::CHARGE)
            ->where('mode', 'live')
            ->where('status', PaymentTransaction::SUCCESSFUL)
            ->whereBetween('paid_at', [$range->startUtc(), $range->endUtc()])
            ->whereNull('fee')
            ->exists();
    }

    /**
     * @param  array<string, array<string, string>>  $gross
     * @param  array<string, array<string, string>>  $deductions
     * @return list<ChartSeries>
     */
    private function revenueCharts(DateRange $range, array $gross, array $deductions): array
    {
        $currencies = array_unique([...array_keys($gross), ...array_keys($deductions)]) ?: [$this->settings->reportingTotals()->reportingCurrency()];
        $empty = array_fill_keys($range->buckets(), '0.0000');
        $charts = [];

        foreach ($currencies as $currency) {
            $g = $gross[$currency] ?? $empty;
            $d = $deductions[$currency] ?? $empty;
            $net = [];

            foreach ($g as $bucket => $value) {
                $net[$bucket] = bcsub($value, $d[$bucket] ?? '0', 4);
            }

            $charts[] = new ChartSeries('revenue_over_time', 'Revenue', ChartSeries::BAR, KpiValue::MONEY, [
                ['key' => 'gross', 'label' => 'Gross', 'points' => TimeSeries::points($g)],
                ['key' => 'net', 'label' => 'Net', 'points' => TimeSeries::points($net)],
            ], $range->interval, $currency);
        }

        return $charts;
    }

    /**
     * @return list<ChartSeries>
     */
    private function planRevenueCharts(DateRange $range): array
    {
        $rows = DB::connection('landlord')->table('payment_transactions as pt')
            ->crossJoin(DB::raw("JSON_TABLE(pt.line_items, '$[*]' COLUMNS (line_type VARCHAR(32) PATH '$.type', plan_slug VARCHAR(120) PATH '$.key')) as li"))
            ->leftJoin('plans', 'plans.slug', '=', 'li.plan_slug')
            ->where('pt.type', PaymentTransaction::CHARGE)
            ->where('pt.mode', 'live')
            ->where('pt.status', PaymentTransaction::SUCCESSFUL)
            ->whereBetween('pt.paid_at', [$range->startUtc(), $range->endUtc()])
            ->where('li.line_type', 'plan')
            ->groupBy('pt.currency_code', 'li.plan_slug', 'plans.name')
            ->selectRaw('pt.currency_code, li.plan_slug, plans.name as plan_name, SUM(pt.amount) as revenue')
            ->orderByDesc('revenue')
            ->get();

        $byCurrency = [];

        foreach ($rows as $row) {
            $byCurrency[(string) $row->currency_code][] = ['x' => (string) ($row->plan_name ?? $row->plan_slug), 'y' => bcadd((string) $row->revenue, '0', 4)];
        }

        if ($byCurrency === []) {
            $byCurrency[$this->settings->reportingTotals()->reportingCurrency()] = [];
        }

        $charts = [];

        foreach ($byCurrency as $currency => $points) {
            $charts[] = new ChartSeries('plan_revenue', 'Revenue by plan', ChartSeries::DONUT, KpiValue::MONEY, [
                ['key' => 'revenue', 'label' => 'Gross revenue', 'points' => $points],
            ], null, $currency);
        }

        return $charts;
    }

    private function topTenants(DateRange $range): TableBlock
    {
        $rows = (clone $this->moneyRows())
            ->join('tenants', 'tenants.id', '=', 'payment_transactions.tenant_id')
            ->whereBetween('payment_transactions.paid_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('payment_transactions.tenant_id', 'tenants.name', 'payment_transactions.currency_code')
            ->selectRaw('payment_transactions.tenant_id, tenants.name, payment_transactions.currency_code, SUM(payment_transactions.amount) as net')
            ->orderByDesc('net')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => [
                'tenant_id' => $r->tenant_id,
                'name' => $r->name,
                'net_revenue' => bcadd((string) $r->net, '0', 4),
                'currency_code' => $r->currency_code,
            ])->all();

        return new TableBlock('top_tenants_by_revenue', 'Top tenants by revenue', [
            ['key' => 'name', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'net_revenue', 'label' => 'Net revenue', 'format' => 'money'],
        ], $rows, '/admin/payment-transactions');
    }

    private function latestPayments(): TableBlock
    {
        $rows = DB::connection('landlord')->table('payment_transactions')
            ->join('tenants', 'tenants.id', '=', 'payment_transactions.tenant_id')
            ->where('payment_transactions.type', PaymentTransaction::CHARGE)
            ->where('payment_transactions.mode', 'live')
            ->where('payment_transactions.status', PaymentTransaction::SUCCESSFUL)
            ->orderByDesc('payment_transactions.paid_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['payment_transactions.id', 'payment_transactions.tenant_id', 'tenants.name', 'payment_transactions.amount', 'payment_transactions.currency_code', 'payment_transactions.provider', 'payment_transactions.paid_at'])
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'tenant_id' => $r->tenant_id,
                'tenant' => $r->name,
                'amount' => bcadd((string) $r->amount, '0', 4),
                'currency_code' => $r->currency_code,
                'provider' => $r->provider,
                'paid_at' => CarbonImmutable::parse($r->paid_at, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('latest_payments', 'Latest payments', [
            ['key' => 'tenant', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
            ['key' => 'provider', 'label' => 'Provider', 'format' => 'text'],
            ['key' => 'paid_at', 'label' => 'Paid', 'format' => 'datetime'],
        ], $rows, '/admin/payment-transactions');
    }

    /**
     * Successful live charges, refunds and chargebacks.
     */
    private function moneyRows(): Builder
    {
        return DB::connection('landlord')->table('payment_transactions')
            ->whereIn('payment_transactions.type', [PaymentTransaction::CHARGE, PaymentTransaction::REFUND, PaymentTransaction::CHARGEBACK])
            ->where('payment_transactions.mode', 'live')
            ->where('payment_transactions.status', PaymentTransaction::SUCCESSFUL);
    }
}
