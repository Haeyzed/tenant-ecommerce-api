<?php

declare(strict_types=1);

namespace App\Modules\Customers\Metrics;

use App\Modules\Orders\Metrics\OrderQueries;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer figures (spec §44.2 "Customers"). A customer's orders are their
 * included orders (§44.1: confirmed, live, not cancelled, standard). The
 * customer base is not warehouse-scoped, so these figures ignore the
 * staff warehouse scope.
 */
final readonly class CustomerMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function overview(DateRange $range, MetricsScope $scope): SectionResult
    {
        [$new, $previous, $series] = $this->newCustomers($range);

        return new SectionResult(kpis: [
            KpiValue::count('new_customers', 'New customers', $new, $range, $previous, KpiValue::UP_IS_GOOD, TimeSeries::sparkline($series, true)),
        ]);
    }

    public function customers(DateRange $range, MetricsScope $scope): SectionResult
    {
        [$new, $previousNew, $series] = $this->newCustomers($range);
        $buyers = $this->buyers($range);
        $comparison = $range->comparison();
        $previous = $comparison === null ? null : $this->buyers($comparison);

        return new SectionResult(
            kpis: [
                KpiValue::count('total_customers', 'Total customers', $this->total(), $range, null, KpiValue::NEUTRAL),
                KpiValue::count('new_customers', 'New customers', $new, $range, $previousNew, KpiValue::UP_IS_GOOD, TimeSeries::sparkline($series, true)),
                KpiValue::count('first_time_buyers', 'First-time buyers', $buyers['first_time'], $range, $previous['first_time'] ?? null),
                KpiValue::count('returning_customers', 'Returning customers', $buyers['returning'], $range, $previous['returning'] ?? null),
                KpiValue::rate('repeat_purchase_rate', 'Repeat purchase rate', (string) $buyers['returning'], (string) $buyers['buyers'], $range,
                    $previous === null ? null : [(string) $previous['returning'], (string) $previous['buyers']]),
            ],
            charts: [
                new ChartSeries('new_customers_over_time', 'New customers', ChartSeries::BAR, KpiValue::COUNT,
                    [['key' => 'new_customers', 'label' => 'New customers', 'points' => TimeSeries::points($series, true)]], $range->interval),
                $this->abandonment($range),
            ],
            tables: [$this->topCustomers($range)],
        );
    }

    /**
     * The customers list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        [$new, $previousNew] = $this->newCustomers($range);
        $buyers = $this->buyers($range);
        $comparison = $range->comparison();
        $active = $this->activeSince(now()->subDays(90));
        $recent180 = $this->activeSince(now()->subDays(180));

        return [
            KpiValue::count('total_customers', 'Total customers', $this->total(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('new_customers', 'New customers', $new, $range, $previousNew),
            KpiValue::count('returning_customers', 'Returning customers', $buyers['returning'], $range, $comparison === null ? null : $this->buyers($comparison)['returning']),
            KpiValue::count('active_customers', 'Active (90 days)', $active, $range, null),
            KpiValue::count('inactive_customers', 'Inactive (180 days)', max(0, $this->total() - $recent180), $range, null, KpiValue::DOWN_IS_GOOD),
        ];
    }

    private function total(): int
    {
        return $this->customerBase()->count();
    }

    /**
     * @return array{0: int, 1: int|null, 2: array<string, string>}
     */
    private function newCustomers(DateRange $range): array
    {
        $series = TimeSeries::aggregate($this->customerBase(), 'c.created_at', $range, 'COUNT(*)')[''] ?? array_fill_keys($range->buckets(), '0');
        $comparison = $range->comparison();
        $previous = $comparison === null ? null : (int) (TimeSeries::total($this->customerBase(), 'c.created_at', $comparison, 'COUNT(*)')[''] ?? 0);

        return [(int) TimeSeries::sum($series), $previous, $series];
    }

    /**
     * Customers with an included order in the range: all of them, those
     * whose first included order is in the range, and those who had one
     * before the range too.
     *
     * @return array{buyers: int, first_time: int, returning: int}
     */
    private function buyers(DateRange $range): array
    {
        $start = $range->startUtc();

        $row = DB::connection('tenant')->query()->fromSub(
            $this->includedOrders()
                ->whereNotNull('o.customer_id')
                ->groupBy('o.customer_id')
                ->havingRaw('SUM(CASE WHEN o.confirmed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) > 0', [$start, $range->endUtc()])
                ->selectRaw('o.customer_id, MIN(o.confirmed_at) as first_order, SUM(CASE WHEN o.confirmed_at < ? THEN 1 ELSE 0 END) as before_range', [$start]),
            'b'
        )->selectRaw('COUNT(*) as buyers, SUM(CASE WHEN b.first_order >= ? THEN 1 ELSE 0 END) as first_time, SUM(CASE WHEN b.before_range > 0 THEN 1 ELSE 0 END) as returning_count', [$start])
            ->first();

        return ['buyers' => (int) ($row->buyers ?? 0), 'first_time' => (int) ($row->first_time ?? 0), 'returning' => (int) ($row->returning_count ?? 0)];
    }

    private function activeSince(\DateTimeInterface $since): int
    {
        return $this->customerBase()
            ->whereExists(fn (Builder $q) => $q->fromSub($this->includedOrders()->select('o.customer_id', 'o.confirmed_at'), 'io')
                ->whereColumn('io.customer_id', 'c.id')->where('io.confirmed_at', '>=', $since))
            ->count();
    }

    /**
     * Carts that still hold items after activity in the range ÷ those plus
     * online orders placed in the range (a checkout empties its cart).
     */
    private function abandonment(DateRange $range): ChartSeries
    {
        $between = [$range->startUtc(), $range->endUtc()];
        $abandoned = DB::connection('tenant')->table('carts')->whereBetween('last_activity_at', $between)
            ->whereExists(static fn (Builder $q) => $q->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))->count();
        $ordered = DB::connection('tenant')->table('orders')->where('is_test', false)->where('order_source', 'online')->whereNull('deleted_at')
            ->whereBetween('placed_at', $between)->count();

        $rate = KpiValue::percentOf((string) $abandoned, (string) ($abandoned + $ordered));

        return new ChartSeries('cart_abandonment', 'Cart abandonment', ChartSeries::DONUT, KpiValue::COUNT, [[
            'key' => 'carts',
            'label' => $rate === null ? 'Carts' : "Abandonment rate {$rate}%",
            'points' => [['x' => 'abandoned', 'y' => $abandoned], ['x' => 'ordered', 'y' => $ordered]],
        ]]);
    }

    private function topCustomers(DateRange $range): TableBlock
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $rows = $this->includedOrders()
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereNull('c.anonymized_at')
            ->whereBetween('o.confirmed_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('c.id', 'c.name', 'c.email')
            ->selectRaw('c.id, c.name, c.email, COUNT(*) as orders, SUM((o.subtotal - o.discount_amount) * COALESCE(o.exchange_rate_used, 1)) as net_sales, MAX(o.confirmed_at) as last_order')
            ->orderByDesc('net_sales')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => [
                'customer_id' => (int) $r->id,
                'name' => $r->name,
                'email' => $r->email,
                'orders' => (int) $r->orders,
                'net_sales' => bcadd((string) $r->net_sales, '0', 4),
                'currency_code' => $currency,
                'last_order_at' => CarbonImmutable::parse($r->last_order, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('top_customers', 'Top customers', [
            ['key' => 'name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => KpiValue::COUNT],
            ['key' => 'net_sales', 'label' => 'Net sales', 'format' => 'money'],
            ['key' => 'last_order_at', 'label' => 'Last order', 'format' => 'datetime'],
        ], $rows, '/admin/customers');
    }

    /**
     * Customers not anonymised or deleted (§26.4).
     */
    private function customerBase(): Builder
    {
        return DB::connection('tenant')->table('customers as c')->whereNull('c.anonymized_at')->whereNull('c.deleted_at');
    }

    /**
     * The shared included-order definition (§44.1), unscoped.
     */
    private function includedOrders(): Builder
    {
        return OrderQueries::included(MetricsScope::all());
    }
}
