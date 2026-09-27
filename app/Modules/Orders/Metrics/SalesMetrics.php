<?php

declare(strict_types=1);

namespace App\Modules\Orders\Metrics;

use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Support\Facades\DB;

/**
 * Sales figures (spec §44.2 "Sales") for the overview and sales sections.
 * Everything is tax-exclusive and in the base currency, from included
 * orders confirmed in the range; returns are dated by the refund.
 */
final readonly class SalesMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function overview(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = $this->currency();
        $net = $this->netSalesSeries($range, $scope);
        $totals = $this->totals($range, $scope);
        $previous = ($comparison = $range->comparison()) === null ? null : $this->totals($comparison, $scope);
        $refunds = $this->refunds($range, $scope);

        return new SectionResult(
            kpis: [
                KpiValue::money('net_sales', 'Net sales', $totals['net_sales'], $currency, $range, $previous['net_sales'] ?? null, KpiValue::UP_IS_GOOD, TimeSeries::sparkline($net)),
                KpiValue::count('orders', 'Orders', $totals['orders'], $range, $previous['orders'] ?? null),
                KpiValue::money('average_order_value', 'Average order value', $totals['average_order_value'], $currency, $range, $previous['average_order_value'] ?? null),
                KpiValue::money('refunds', 'Refunds', $refunds, $currency, $range, $comparison === null ? null : $this->refunds($comparison, $scope), KpiValue::DOWN_IS_GOOD),
            ],
            charts: [$this->netSalesChart($net, $range, $currency)],
            tables: [$this->topProducts($range, $scope, $currency)],
        );
    }

    public function sales(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = $this->currency();
        $net = $this->netSalesSeries($range, $scope);
        $t = $this->totals($range, $scope);
        $p = ($comparison = $range->comparison()) === null ? null : $this->totals($comparison, $scope);
        $money = static fn (string $key, string $label, string $polarity = KpiValue::UP_IS_GOOD, ?array $spark = null, ?string $note = null): KpiValue => KpiValue::money($key, $label, $t[$key], $currency, $range, $p[$key] ?? null, $polarity, $spark, false, $note);

        return new SectionResult(
            kpis: [
                $money('gross_sales', 'Gross sales'),
                $money('discounts', 'Discounts', KpiValue::NEUTRAL),
                $money('returns', 'Returns', KpiValue::DOWN_IS_GOOD),
                $money('net_sales', 'Net sales', KpiValue::UP_IS_GOOD, TimeSeries::sparkline($net)),
                $money('tax', 'Tax', KpiValue::NEUTRAL),
                $money('shipping_revenue', 'Shipping revenue'),
                $money('total_sales', 'Total sales'),
                $money('average_order_value', 'Average order value'),
                KpiValue::count('units_sold', 'Units sold', (int) floor((float) $t['units_sold']), $range, $p === null ? null : (int) floor((float) $p['units_sold'])),
                $money('gross_profit', 'Gross profit', KpiValue::UP_IS_GOOD, null, $t['cost_unknown_lines'] > 0 ? "{$t['cost_unknown_lines']} lines without a cost are excluded" : null),
            ],
            charts: [
                $this->netSalesChart($net, $range, $currency),
                $this->byCategory($range, $scope, $currency),
                $this->bySource($range, $scope, $currency),
                $this->byPaymentMethod($range, $scope, $currency),
            ],
            tables: [$this->topProducts($range, $scope, $currency)],
        );
    }

    /**
     * Net sales for the orders KPI strip (§44.4).
     */
    public function netSales(DateRange $range, MetricsScope $scope): string
    {
        return $this->totals($range, $scope)['net_sales'];
    }

    public function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }

    /**
     * @return array{gross_sales: string, discounts: string, returns: string, net_sales: string, tax: string, shipping_revenue: string, total_sales: string, orders: int, average_order_value: string, units_sold: string, gross_profit: string, cost_unknown_lines: int}
     */
    private function totals(DateRange $range, MetricsScope $scope): array
    {
        $between = [$range->startUtc(), $range->endUtc()];
        $gross = OrderQueries::LINE_GROSS;
        $discount = OrderQueries::LINE_DISCOUNT;

        $lines = OrderQueries::includedLines($scope)->whereBetween('o.confirmed_at', $between)->selectRaw(
            "SUM({$gross}) as gross, SUM({$discount}) as discount, SUM(oi.quantity) as units,"
            ." SUM(CASE WHEN oi.unit_cost_snapshot IS NOT NULL THEN {$gross} - {$discount} - oi.unit_cost_snapshot * oi.quantity * ".OrderQueries::FX.' ELSE 0 END) as profit,'
            .' SUM(CASE WHEN oi.unit_cost_snapshot IS NULL THEN 1 ELSE 0 END) as cost_unknown'
        )->first();

        $fx = OrderQueries::FX;
        $orders = OrderQueries::included($scope)->whereBetween('o.confirmed_at', $between)->selectRaw(
            "COUNT(*) as orders, SUM(o.reward_points_discount_amount * {$fx}) as points, SUM(o.tax_amount * {$fx}) as tax,"
            ." SUM((o.shipping_amount - o.shipping_discount_amount) * {$fx}) as shipping, SUM(o.total * {$fx}) as total"
        )->first();

        $n = static fn (mixed $v): string => bcadd((string) ($v ?? '0'), '0', 4);
        $grossSales = $n($lines->gross ?? null);
        $lineDiscounts = $n($lines->discount ?? null);
        $discounts = bcadd($lineDiscounts, $n($orders->points ?? null), 4);
        $returns = OrderQueries::returnsValue($scope, $range);
        $count = (int) ($orders->orders ?? 0);

        return [
            'gross_sales' => $grossSales,
            'discounts' => $discounts,
            'returns' => $returns,
            'net_sales' => bcsub(bcsub($grossSales, $discounts, 4), $returns, 4),
            'tax' => $n($orders->tax ?? null),
            'shipping_revenue' => $n($orders->shipping ?? null),
            'total_sales' => $n($orders->total ?? null),
            'orders' => $count,
            'average_order_value' => $count === 0 ? '0.0000' : bcdiv(bcsub($grossSales, $lineDiscounts, 4), (string) $count, 4),
            'units_sold' => $n($lines->units ?? null),
            'gross_profit' => $n($lines->profit ?? null),
            'cost_unknown_lines' => (int) ($lines->cost_unknown ?? 0),
        ];
    }

    /**
     * Net sales per bucket: line value less discounts by confirmation,
     * less reward-point discounts, less returns by refund date.
     *
     * @return array<string, string>
     */
    private function netSalesSeries(DateRange $range, MetricsScope $scope): array
    {
        $lines = TimeSeries::aggregate(OrderQueries::includedLines($scope), 'o.confirmed_at', $range,
            'SUM('.OrderQueries::LINE_GROSS.' - '.OrderQueries::LINE_DISCOUNT.')')[''] ?? [];
        $points = TimeSeries::aggregate(OrderQueries::included($scope), 'o.confirmed_at', $range,
            'SUM(o.reward_points_discount_amount * '.OrderQueries::FX.')')[''] ?? [];
        $returns = TimeSeries::aggregate(
            OrderQueries::reversals($scope, ['refund', 'chargeback'])->where('o.order_type', '!=', 'gift_card_purchase'),
            'op.paid_at', $range, OrderQueries::RETURNS_SUM)[''] ?? [];

        $series = [];

        foreach ($range->buckets() as $bucket) {
            $series[$bucket] = bcsub(bcsub($lines[$bucket] ?? '0', $points[$bucket] ?? '0', 4), $returns[$bucket] ?? '0', 4);
        }

        return $series;
    }

    private function refunds(DateRange $range, MetricsScope $scope): string
    {
        $value = OrderQueries::reversals($scope)->whereBetween('op.paid_at', [$range->startUtc(), $range->endUtc()])
            ->value(DB::raw('SUM(-op.amount_paid * COALESCE(op.exchange_rate_used, o.exchange_rate_used, 1))'));

        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    /**
     * @param  array<string, string>  $net
     */
    private function netSalesChart(array $net, DateRange $range, string $currency): ChartSeries
    {
        return new ChartSeries('net_sales_over_time', 'Net sales', ChartSeries::LINE, KpiValue::MONEY,
            [['key' => 'net_sales', 'label' => 'Net sales', 'points' => TimeSeries::points($net)]], $range->interval, $currency);
    }

    private function byCategory(DateRange $range, MetricsScope $scope, string $currency): ChartSeries
    {
        $rows = OrderQueries::includedLines($scope)
            ->whereBetween('o.confirmed_at', [$range->startUtc(), $range->endUtc()])
            ->leftJoin('product_categories as pc', static fn ($j) => $j->on('pc.product_id', '=', 'oi.product_id')->where('pc.is_primary', '=', true))
            ->leftJoin('categories as c', 'c.id', '=', 'pc.category_id')
            ->groupBy('c.name')
            ->selectRaw("COALESCE(c.name, 'Uncategorised') as label, SUM(".OrderQueries::LINE_GROSS.' - '.OrderQueries::LINE_DISCOUNT.') as value')
            ->orderByDesc('value')
            ->limit(12)
            ->get();

        return $this->donut('sales_by_category', 'Sales by category', $rows, $currency);
    }

    private function bySource(DateRange $range, MetricsScope $scope, string $currency): ChartSeries
    {
        $rows = OrderQueries::includedLines($scope)
            ->whereBetween('o.confirmed_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('o.order_source')
            ->selectRaw('o.order_source as label, SUM('.OrderQueries::LINE_GROSS.' - '.OrderQueries::LINE_DISCOUNT.') as value')
            ->orderByDesc('value')
            ->get();

        return $this->donut('sales_by_source', 'Sales by order source', $rows, $currency);
    }

    /**
     * Money received per payment method in the range (successful live
     * payments of scoped orders).
     */
    private function byPaymentMethod(DateRange $range, MetricsScope $scope, string $currency): ChartSeries
    {
        $rows = $scope->orders(DB::connection('tenant')->table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('op.kind', 'payment')->where('op.status', 'successful')->where('op.mode', 'live')->where('o.is_test', false)
            ->whereBetween('op.paid_at', [$range->startUtc(), $range->endUtc()]))
            ->groupBy('op.payment_method')
            ->selectRaw('op.payment_method as label, SUM(op.amount_paid * COALESCE(op.exchange_rate_used, o.exchange_rate_used, 1)) as value')
            ->orderByDesc('value')
            ->get();

        return $this->donut('sales_by_payment_method', 'Payments by method', $rows, $currency);
    }

    /**
     * @param  iterable<object{label: string|null, value: string|null}>  $rows
     */
    private function donut(string $key, string $label, iterable $rows, string $currency): ChartSeries
    {
        $points = [];

        foreach ($rows as $row) {
            $points[] = ['x' => str_replace('_', ' ', (string) $row->label), 'y' => bcadd((string) ($row->value ?? '0'), '0', 4)];
        }

        return new ChartSeries($key, $label, ChartSeries::DONUT, KpiValue::MONEY, [['key' => $key, 'label' => $label, 'points' => $points]], null, $currency);
    }

    private function topProducts(DateRange $range, MetricsScope $scope, string $currency): TableBlock
    {
        $rows = OrderQueries::includedLines($scope)
            ->whereBetween('o.confirmed_at', [$range->startUtc(), $range->endUtc()])
            ->whereNotNull('oi.product_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->groupBy('oi.product_id', 'p.name')
            ->selectRaw('oi.product_id, p.name, SUM(oi.quantity) as units, SUM('.OrderQueries::LINE_GROSS.' - '.OrderQueries::LINE_DISCOUNT.') as net_sales')
            ->orderByDesc('net_sales')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => [
                'product_id' => (int) $r->product_id,
                'name' => $r->name,
                'units' => bcadd((string) $r->units, '0', 3),
                'net_sales' => bcadd((string) $r->net_sales, '0', 4),
                'currency_code' => $currency,
            ])->all();

        return new TableBlock('top_products', 'Top products', [
            ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'units', 'label' => 'Units', 'format' => 'quantity'],
            ['key' => 'net_sales', 'label' => 'Net sales', 'format' => 'money'],
        ], $rows, '/admin/products');
    }
}
