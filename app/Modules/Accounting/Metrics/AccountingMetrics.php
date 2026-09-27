<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Metrics;

use App\Modules\Accounting\Models\AccountingPostingRequest;
use App\Modules\Accounting\Services\AccountingService;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TimeSeries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The accounting section (spec §44.3): revenue, expenses and net profit over
 * the range from the ledger, and receivables, payables and cash as of now.
 * Journal entry dates are local calendar dates, so they are bucketed as
 * such (no timezone shift). The ledger is not warehouse-scoped.
 */
final readonly class AccountingMetrics
{
    public function __construct(private AccountingService $accounting) {}

    public function accounting(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = $this->accounting->currency();
        [$revenue, $expenses] = $this->profitSeries($range);
        $previous = ($comparison = $range->comparison()) === null ? null : $this->profitSeries($comparison);
        $net = [];

        foreach ($revenue as $bucket => $value) {
            $net[$bucket] = bcsub($value, $expenses[$bucket], 4);
        }

        $sum = static fn (?array $points): ?string => $points === null ? null : TimeSeries::sum($points);
        $prevNet = $previous === null ? null : bcsub(TimeSeries::sum($previous[0]), TimeSeries::sum($previous[1]), 4);

        return new SectionResult(
            kpis: [
                KpiValue::money('revenue', 'Revenue', TimeSeries::sum($revenue), $currency, $range, $sum($previous[0] ?? null), KpiValue::UP_IS_GOOD, TimeSeries::sparkline($revenue)),
                KpiValue::money('expenses', 'Expenses', TimeSeries::sum($expenses), $currency, $range, $sum($previous[1] ?? null), KpiValue::DOWN_IS_GOOD),
                KpiValue::money('net_profit', 'Net profit', TimeSeries::sum($net), $currency, $range, $prevNet, KpiValue::UP_IS_GOOD, TimeSeries::sparkline($net)),
                KpiValue::money('receivables', 'Receivables', $this->balance(['accounts_receivable'], true), $currency, $range, null, KpiValue::NEUTRAL),
                KpiValue::money('payables', 'Payables', $this->balance(['accounts_payable', 'accounts_payable_sellers'], false), $currency, $range, null, KpiValue::NEUTRAL),
                KpiValue::money('cash_balance', 'Cash balance', $this->cashBalance(), $currency, $range, null, KpiValue::UP_IS_GOOD),
            ],
            charts: [new ChartSeries('profit_over_time', 'Profit', ChartSeries::BAR, KpiValue::MONEY, [
                ['key' => 'revenue', 'label' => 'Revenue', 'points' => TimeSeries::points($revenue)],
                ['key' => 'expenses', 'label' => 'Expenses', 'points' => TimeSeries::points($expenses)],
                ['key' => 'net_profit', 'label' => 'Net profit', 'points' => TimeSeries::points($net)],
            ], $range->interval, $currency)],
        );
    }

    /**
     * @return list<Alert>
     */
    public function alerts(MetricsScope $scope): array
    {
        $failed = AccountingPostingRequest::query()->where('status', AccountingPostingRequest::FAILED)->count();

        return $failed === 0 ? [] : [new Alert('accounting_postings_failed', Alert::CRITICAL,
            "{$failed} accounting ".($failed === 1 ? 'posting has' : 'postings have').' failed. Check the fiscal periods, then retry.', $failed, '/admin/accounting/posting-requests?status=failed')];
    }

    /**
     * Revenue (credit-normal, contra accounts reduce it) and expenses per
     * bucket.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function profitSeries(DateRange $range): array
    {
        $rows = DB::connection('tenant')->table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->join('account_categories as c', 'c.id', '=', 'a.account_category_id')
            ->whereIn('c.account_type', ['revenue', 'expense'])
            ->whereBetween('je.entry_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->groupBy('je.entry_date', 'c.account_type')
            ->selectRaw("je.entry_date, c.account_type, SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE -l.amount END) as net_credit")
            ->get();

        $revenue = $expenses = array_fill_keys($range->buckets(), '0.0000');

        foreach ($rows as $row) {
            $bucket = $range->bucketOf(CarbonImmutable::parse((string) $row->entry_date, $range->timezone));

            if (! array_key_exists($bucket, $revenue)) {
                continue;
            }

            $row->account_type === 'revenue'
                ? $revenue[$bucket] = bcadd($revenue[$bucket], (string) $row->net_credit, 4)
                : $expenses[$bucket] = bcsub($expenses[$bucket], (string) $row->net_credit, 4);
        }

        return [$revenue, $expenses];
    }

    /**
     * @param  list<string>  $systemKeys
     */
    private function balance(array $systemKeys, bool $debitNormal): string
    {
        $net = DB::connection('tenant')->table('journal_entry_lines as l')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.system_key', $systemKeys)
            ->value(DB::raw("SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE -l.amount END)"));

        $net = bcadd((string) ($net ?? '0'), '0', 4);

        return $debitNormal ? $net : bcsub('0', $net, 4);
    }

    private function cashBalance(): string
    {
        $ids = $this->accounting->cashAccountIds();
        $net = DB::connection('tenant')->table('journal_entry_lines')->whereIn('account_id', $ids === [] ? [0] : $ids)
            ->value(DB::raw("SUM(CASE WHEN type = 'debit' THEN amount ELSE -amount END)"));

        return bcadd((string) ($net ?? '0'), '0', 4);
    }
}
