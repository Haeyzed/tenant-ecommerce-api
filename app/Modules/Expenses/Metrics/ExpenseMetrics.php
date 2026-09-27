<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Metrics;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\IncomeEntry;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expense figures (spec §44.3 "expenses", §44.4): expenses by expense_date
 * in the range (every status), unpaid expenses now, other income received
 * by received_date. Dates are local calendar dates.
 */
final readonly class ExpenseMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function expenses(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = $this->currency();
        $comparison = $range->comparison();
        $unpaid = $this->unpaid();

        return new SectionResult(
            kpis: [
                KpiValue::money('expenses', 'Expenses', $this->total($range), $currency, $range, $comparison === null ? null : $this->total($comparison), KpiValue::DOWN_IS_GOOD),
                KpiValue::money('unpaid_expenses', 'Unpaid expenses', $unpaid['amount'], $currency, $range, null, KpiValue::DOWN_IS_GOOD, null, false, "{$unpaid['count']} unpaid"),
                KpiValue::money('other_income', 'Other income', $this->income($range), $currency, $range, $comparison === null ? null : $this->income($comparison)),
            ],
            charts: [$this->byCategory($range, $currency)],
        );
    }

    /**
     * The expenses list KPI strip (§44.4): expenses (range), unpaid, and the
     * top category.
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $currency = $this->currency();
        $comparison = $range->comparison();
        $unpaid = $this->unpaid();
        $top = $this->categories($range)->first();

        return [
            KpiValue::money('expenses', 'Expenses', $this->total($range), $currency, $range, $comparison === null ? null : $this->total($comparison), KpiValue::DOWN_IS_GOOD),
            KpiValue::money('unpaid_expenses', 'Unpaid', $unpaid['amount'], $currency, $range, null, KpiValue::DOWN_IS_GOOD, null, false, "{$unpaid['count']} unpaid"),
            KpiValue::money('top_category', 'Top category', $top === null ? '0' : (string) $top->total, $currency, $range, null, KpiValue::NEUTRAL, null, false, $top?->name),
        ];
    }

    private function total(DateRange $range): string
    {
        return bcadd((string) ($this->inRange($range)->sum('e.amount') ?: '0'), '0', 4);
    }

    /**
     * @return array{count: int, amount: string}
     */
    private function unpaid(): array
    {
        $row = DB::connection('tenant')->table('expenses')->where('status', Expense::PENDING)->selectRaw('COUNT(*) as unpaid, SUM(amount) as amount')->first();

        return ['count' => (int) ($row->unpaid ?? 0), 'amount' => bcadd((string) ($row->amount ?? '0'), '0', 4)];
    }

    private function income(DateRange $range): string
    {
        $sum = DB::connection('tenant')->table('income_entries')->where('status', IncomeEntry::RECEIVED)
            ->whereBetween('received_date', [$range->from->toDateString(), $range->to->toDateString()])->sum('amount');

        return bcadd((string) ($sum ?: '0'), '0', 4);
    }

    private function byCategory(DateRange $range, string $currency): ChartSeries
    {
        $points = $this->categories($range)->map(static fn ($r): array => ['x' => $r->name, 'y' => bcadd((string) $r->total, '0', 4)])->values()->all();

        return new ChartSeries('expenses_by_category', 'Expenses by category', ChartSeries::DONUT, KpiValue::MONEY,
            [['key' => 'expenses', 'label' => 'Expenses', 'points' => $points]], null, $currency);
    }

    /**
     * @return Collection<int, object{name: string, total: string}>
     */
    private function categories(DateRange $range): Collection
    {
        return $this->inRange($range)->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->groupBy('c.id', 'c.name')->selectRaw('c.name, SUM(e.amount) as total')->orderByDesc('total')->limit(10)->get();
    }

    private function inRange(DateRange $range): Builder
    {
        return DB::connection('tenant')->table('expenses as e')->whereBetween('e.expense_date', [$range->from->toDateString(), $range->to->toDateString()]);
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
