<?php

declare(strict_types=1);

namespace App\Modules\Pos\Metrics;

use App\Modules\Pos\Models\PosSession;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The POS section (spec §44.3): sales and transactions in the range
 * (confirmed, non-test, not voided POS orders, base currency, by
 * placed_at), sessions open now, the net cash variance of sessions closed
 * in the range, and sales by register. The alert lists sessions of the
 * last 7 days whose variance exceeded pos_cash_variance_threshold.
 */
final readonly class PosMetrics
{
    private const int FLAG_WINDOW_DAYS = 7;

    public function __construct(private TenantSettingsService $settings) {}

    public function pos(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = $this->currency();
        $comparison = $range->comparison();
        $current = $this->sales($range);
        $previous = $comparison === null ? null : $this->sales($comparison);
        $variance = DB::connection('tenant')->table('pos_sessions')->where('status', PosSession::CLOSED)
            ->whereBetween('closed_at', [$range->startUtc(), $range->endUtc()])->sum('cash_variance');

        return new SectionResult(
            kpis: [
                KpiValue::money('pos_sales', 'POS sales', $current['sales'], $currency, $range, $previous['sales'] ?? null),
                KpiValue::count('pos_transactions', 'Transactions', $current['count'], $range, $previous['count'] ?? null),
                KpiValue::count('open_sessions', 'Open sessions', PosSession::query()->where('status', PosSession::OPEN)->count(), $range, null, KpiValue::NEUTRAL),
                KpiValue::money('cash_variance', 'Cash variance', bcadd((string) ($variance ?: '0'), '0', 4), $currency, $range, null, KpiValue::NEUTRAL),
            ],
            tables: [$this->byRegister($range, $currency)],
        );
    }

    /**
     * @return list<Alert>
     */
    public function alerts(MetricsScope $scope): array
    {
        $threshold = $this->settings->get('pos_cash_variance_threshold');

        if ($threshold === null) {
            return [];
        }

        $count = DB::connection('tenant')->table('pos_sessions')->where('status', PosSession::CLOSED)
            ->where('closed_at', '>=', now()->subDays(self::FLAG_WINDOW_DAYS))
            ->whereRaw('ABS(cash_variance) > ?', [(string) $threshold])->count();

        return $count === 0 ? [] : [new Alert('pos_variances_flagged', Alert::WARNING,
            "{$count} POS ".($count === 1 ? 'session' : 'sessions').' closed with a cash variance above the threshold this week.', $count, '/admin/pos/sessions?status=closed')];
    }

    /**
     * @return array{sales: string, count: int}
     */
    private function sales(DateRange $range): array
    {
        $row = $this->orders($range)->selectRaw('COUNT(*) as orders, SUM(o.base_currency_amount) as sales')->first();

        return ['sales' => bcadd((string) ($row->sales ?? '0'), '0', 4), 'count' => (int) ($row->orders ?? 0)];
    }

    private function byRegister(DateRange $range, string $currency): TableBlock
    {
        $rows = $this->orders($range)
            ->leftJoin('pos_sessions as s', 's.id', '=', 'o.pos_session_id')
            ->leftJoin('pos_registers as r', 'r.id', '=', 's.pos_register_id')
            ->groupBy('r.id', 'r.name')
            ->selectRaw('r.id, r.name, COUNT(*) as orders, SUM(o.base_currency_amount) as sales')
            ->orderByDesc('sales')->limit(TableBlock::MAX_ROWS)->get()
            ->map(static fn ($r): array => [
                'register_id' => $r->id === null ? null : (int) $r->id,
                'name' => $r->name ?? 'Without a session',
                'orders' => (int) $r->orders,
                'sales' => bcadd((string) $r->sales, '0', 4),
                'currency_code' => $currency,
            ])->all();

        return new TableBlock('sales_by_register', 'Sales by register', [
            ['key' => 'name', 'label' => 'Register', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Transactions', 'format' => KpiValue::COUNT],
            ['key' => 'sales', 'label' => 'Sales', 'format' => 'money'],
        ], $rows, '/admin/pos/sales');
    }

    private function orders(DateRange $range): Builder
    {
        return DB::connection('tenant')->table('orders as o')->where('o.order_source', 'pos')->where('o.is_test', false)
            ->whereNotNull('o.confirmed_at')->whereNull('o.cancelled_at')->whereNull('o.deleted_at')
            ->whereBetween('o.placed_at', [$range->startUtc(), $range->endUtc()]);
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
