<?php

declare(strict_types=1);

namespace App\Modules\Orders\Metrics;

use App\Modules\Orders\Models\Order;
use App\Shared\Metrics\Alert;
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
 * Order figures (spec §44.2 "Orders"): live orders by placed_at, counted by
 * status; returned orders; the outstanding balance as of now.
 */
final readonly class OrderMetrics
{
    /** Status groups of the orders section (§44.2). */
    private const array GROUPS = [
        'pending' => [Order::PENDING],
        'processing' => [Order::PROCESSING],
        'shipped' => [Order::PARTIALLY_SHIPPED, Order::SHIPPED],
        'delivered' => [Order::DELIVERED, Order::COMPLETED],
        'cancelled' => [Order::CANCELLED],
        'refunded' => [Order::REFUNDED],
    ];

    /** An unpaid order "about to expire" does so within this many minutes. */
    private const int EXPIRY_WINDOW_MINUTES = 60;

    public function __construct(private SalesMetrics $sales) {}

    public function orders(DateRange $range, MetricsScope $scope): SectionResult
    {
        $byStatus = TimeSeries::aggregate(OrderQueries::placed($scope), 'o.placed_at', $range, 'COUNT(*)', 'o.status');
        $empty = array_fill_keys($range->buckets(), '0');
        $total = $empty;

        foreach ($byStatus as $points) {
            foreach ($points as $bucket => $value) {
                $total[$bucket] = bcadd($total[$bucket], $value, 0);
            }
        }

        $counts = $this->groupCounts(array_map(static fn (array $p): string => TimeSeries::sum($p), $byStatus));
        $comparison = $range->comparison();
        $previous = $comparison === null ? null : $this->groupCounts(TimeSeries::total(OrderQueries::placed($scope), 'o.placed_at', $comparison, 'COUNT(*)', 'o.status'));
        $previousTotal = $comparison === null ? null : (int) (TimeSeries::total(OrderQueries::placed($scope), 'o.placed_at', $comparison, 'COUNT(*)')[''] ?? 0);
        $currency = $this->sales->currency();

        $kpis = [KpiValue::count('orders_total', 'Orders', (int) TimeSeries::sum($total), $range, $previousTotal, KpiValue::UP_IS_GOOD, TimeSeries::sparkline($total, true))];

        foreach (array_keys(self::GROUPS) as $key) {
            $polarity = match ($key) {
                'cancelled', 'refunded' => KpiValue::DOWN_IS_GOOD,
                'delivered' => KpiValue::UP_IS_GOOD,
                default => KpiValue::NEUTRAL,
            };
            $kpis[] = KpiValue::count($key, ucfirst($key), $counts[$key], $range, $previous[$key] ?? null, $polarity);
        }

        $kpis[] = KpiValue::count('returned', 'Returned', $this->returned($range, $scope), $range,
            $comparison === null ? null : $this->returned($comparison, $scope), KpiValue::DOWN_IS_GOOD);
        $kpis[] = KpiValue::money('outstanding_balance', 'Outstanding balance', $this->outstandingBalance($scope), $currency, $range, null, KpiValue::DOWN_IS_GOOD);

        return new SectionResult(
            kpis: $kpis,
            charts: [
                new ChartSeries('orders_over_time', 'Orders', ChartSeries::BAR, KpiValue::COUNT,
                    [['key' => 'orders', 'label' => 'Orders', 'points' => TimeSeries::points($total, true)]], $range->interval),
                new ChartSeries('orders_by_status', 'Orders by status', ChartSeries::DONUT, KpiValue::COUNT, [[
                    'key' => 'status',
                    'label' => 'Status',
                    'points' => array_values(array_map(static fn (string $k, int $v): array => ['x' => $k, 'y' => $v], array_keys(self::GROUPS), array_intersect_key($counts, self::GROUPS))),
                ]]),
            ],
            tables: [$this->fulfilmentTimes($range, $scope), $this->expiringUnpaid($scope, $currency)],
        );
    }

    /**
     * The orders list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $comparison = $range->comparison();
        $current = $this->groupCounts(TimeSeries::total(OrderQueries::placed($scope), 'o.placed_at', $range, 'COUNT(*)', 'o.status'));
        $previous = $comparison === null ? null : $this->groupCounts(TimeSeries::total(OrderQueries::placed($scope), 'o.placed_at', $comparison, 'COUNT(*)', 'o.status'));

        return [
            KpiValue::count('orders', 'Orders', array_sum($current), $range, $previous === null ? null : array_sum($previous)),
            KpiValue::count('pending', 'Pending', $current['pending'], $range, $previous['pending'] ?? null, KpiValue::NEUTRAL),
            KpiValue::count('processing', 'Processing', $current['processing'], $range, $previous['processing'] ?? null, KpiValue::NEUTRAL),
            KpiValue::count('shipped', 'Shipped', $current['shipped'], $range, $previous['shipped'] ?? null, KpiValue::NEUTRAL),
            KpiValue::count('delivered', 'Delivered', $current['delivered'], $range, $previous['delivered'] ?? null),
            KpiValue::count('cancelled', 'Cancelled', $current['cancelled'], $range, $previous['cancelled'] ?? null, KpiValue::DOWN_IS_GOOD),
            KpiValue::money('net_sales', 'Net sales', $this->sales->netSales($range, $scope), $this->sales->currency(), $range,
                $comparison === null ? null : $this->sales->netSales($comparison, $scope)),
        ];
    }

    /**
     * @return list<Alert>
     */
    public function alerts(MetricsScope $scope): array
    {
        $expiring = $this->expiringQuery($scope)->count();

        return $expiring === 0 ? [] : [new Alert('unpaid_orders_expiring', Alert::WARNING,
            "{$expiring} unpaid ".($expiring === 1 ? 'order expires' : 'orders expire').' within the hour.', $expiring, '/admin/orders?payment_status=unpaid')];
    }

    /**
     * @param  array<string, string>  $byStatus  status => count
     * @return array<string, int> group => count, plus "_other" for statuses outside the groups (sums to the total)
     */
    private function groupCounts(array $byStatus): array
    {
        $counts = array_fill_keys(array_keys(self::GROUPS), 0);
        $other = 0;

        foreach ($byStatus as $status => $count) {
            $group = null;

            foreach (self::GROUPS as $key => $statuses) {
                if (in_array($status, $statuses, true)) {
                    $group = $key;
                }
            }

            $group === null ? $other += (int) $count : $counts[$group] += (int) $count;
        }

        return $counts + ['_other' => $other];
    }

    /**
     * Orders placed in the range with a return that came back (§44.2).
     */
    private function returned(DateRange $range, MetricsScope $scope): int
    {
        return OrderQueries::placed($scope)
            ->whereBetween('o.placed_at', [$range->startUtc(), $range->endUtc()])
            ->whereExists(static fn (Builder $q) => $q->from('order_returns as r')->whereColumn('r.order_id', 'o.id')
                ->whereIn('r.status', ['received', 'refunded', 'exchanged', 'closed']))
            ->count();
    }

    /**
     * Σ (total − net paid) of confirmed, non-cancelled live orders with a
     * balance, as of now.
     */
    private function outstandingBalance(MetricsScope $scope): string
    {
        $paid = DB::connection('tenant')->table('order_payments')->where('status', 'successful')
            ->groupBy('order_id')->selectRaw('order_id, SUM(amount_paid) as net_paid');

        $value = OrderQueries::placed($scope)
            ->whereNotNull('o.confirmed_at')
            ->where('o.status', '!=', Order::CANCELLED)
            ->leftJoinSub($paid, 'paid', 'paid.order_id', '=', 'o.id')
            ->whereRaw('o.total - COALESCE(paid.net_paid, 0) > 0')
            ->value(DB::raw('SUM((o.total - COALESCE(paid.net_paid, 0)) * '.OrderQueries::FX.')'));

        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    /**
     * Average time from confirmation to dispatch and to delivery, by
     * fulfilment type, of shipments dispatched in the range.
     */
    private function fulfilmentTimes(DateRange $range, MetricsScope $scope): TableBlock
    {
        $rows = $scope->column(DB::connection('tenant')->table('shipments as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('o.is_test', false)
            ->whereNotNull('o.confirmed_at')
            ->whereBetween('s.dispatched_at', [$range->startUtc(), $range->endUtc()]), 's.warehouse_id')
            ->groupBy('s.fulfillment_type')
            ->selectRaw('s.fulfillment_type, COUNT(*) as shipments,'
                .' AVG(TIMESTAMPDIFF(SECOND, o.confirmed_at, s.dispatched_at)) as to_dispatch,'
                .' AVG(CASE WHEN s.delivered_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, o.confirmed_at, s.delivered_at) END) as to_delivery')
            ->get()
            ->map(static fn ($r): array => [
                'fulfillment_type' => $r->fulfillment_type,
                'shipments' => (int) $r->shipments,
                'average_time_to_dispatch' => $r->to_dispatch === null ? null : (int) round((float) $r->to_dispatch),
                'average_time_to_delivery' => $r->to_delivery === null ? null : (int) round((float) $r->to_delivery),
            ])->all();

        return new TableBlock('fulfilment_times', 'Time to dispatch and delivery', [
            ['key' => 'fulfillment_type', 'label' => 'Fulfilment', 'format' => 'text'],
            ['key' => 'shipments', 'label' => 'Shipments', 'format' => KpiValue::COUNT],
            ['key' => 'average_time_to_dispatch', 'label' => 'To dispatch', 'format' => KpiValue::DURATION],
            ['key' => 'average_time_to_delivery', 'label' => 'To delivery', 'format' => KpiValue::DURATION],
        ], $rows, '/admin/shipments');
    }

    private function expiringUnpaid(MetricsScope $scope, string $currency): TableBlock
    {
        $rows = $this->expiringQuery($scope)
            ->orderBy('o.payment_expires_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['o.id', 'o.order_number', 'o.customer_name', 'o.total', 'o.currency_code', 'o.payment_expires_at'])
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'order_number' => $r->order_number,
                'customer_name' => $r->customer_name,
                'total' => bcadd((string) $r->total, '0', 4),
                'currency_code' => $r->currency_code ?? $currency,
                'payment_expires_at' => CarbonImmutable::parse($r->payment_expires_at, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('unpaid_orders_expiring', 'Unpaid orders about to expire', [
            ['key' => 'order_number', 'label' => 'Order', 'format' => 'text'],
            ['key' => 'customer_name', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'total', 'label' => 'Total', 'format' => 'money'],
            ['key' => 'payment_expires_at', 'label' => 'Expires', 'format' => 'datetime'],
        ], $rows, '/admin/orders?payment_status=unpaid');
    }

    private function expiringQuery(MetricsScope $scope): Builder
    {
        return OrderQueries::placed($scope)
            ->whereNull('o.confirmed_at')
            ->whereIn('o.status', [Order::PENDING, Order::PROCESSING])
            ->whereNotNull('o.payment_expires_at')
            ->whereBetween('o.payment_expires_at', [now(), now()->addMinutes(self::EXPIRY_WINDOW_MINUTES)]);
    }
}
