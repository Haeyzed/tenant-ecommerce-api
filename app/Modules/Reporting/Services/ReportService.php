<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Dashboard\Services\Tenant\TenantDashboardService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Metrics\OrderQueries;
use App\Modules\Orders\Metrics\SalesMetrics;
use App\Modules\Orders\Models\Order;
use App\Modules\Reporting\Support\ReportConnection;
use App\Modules\Reporting\Support\ReportContext;
use App\Modules\Reporting\Support\ReportFilters;
use App\Modules\Reporting\Support\ReportResult;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\TimeSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Advanced reporting (spec §61): read-only reports over existing data.
 * Sales figures are computed by SalesMetrics::summary() with the §44.2
 * definitions, so a report and the dashboard never disagree; profit uses
 * the cost captured at the time of sale (§5.8). Reports read the tenant
 * server's replica when one is configured (§6.6) and honour the
 * requester's staff scope (§25.3).
 */
final class ReportService
{
    /** A line's value net of discounts, tax-exclusive, base currency. */
    private const string NET = '('.OrderQueries::LINE_GROSS.' - '.OrderQueries::LINE_DISCOUNT.')';

    private const string PROFIT = 'SUM(CASE WHEN oi.unit_cost_snapshot IS NOT NULL THEN '.self::NET.' - '.OrderQueries::LINE_COST.' ELSE 0 END)';

    public function __construct(
        private readonly SalesMetrics $sales,
        private readonly TenantDashboardService $dashboard,
        private readonly TenantSettingsService $settings,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * The catalogue (§61.2): report key => [method, extra feature, filter rules].
     *
     * @return array<string, array{0: string, 1: string|null, 2: array<string, list<mixed>>}>
     */
    public static function catalogue(): array
    {
        $range = ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']];
        $id = ['sometimes', 'integer', 'min:1'];
        $orders = [
            ...$range,
            'warehouse_id' => $id, 'customer_id' => $id, 'category_id' => $id, 'brand_id' => $id, 'tag_id' => $id, 'user_id' => $id,
            'payment_method' => ['sometimes', 'string', 'max:32'],
            'order_source' => ['sometimes', Rule::in(Order::SOURCES)],
        ];
        $purchases = ['supplier_id' => $id, 'warehouse_id' => $id, 'status' => ['sometimes', 'string', 'max:24']];
        $stock = ['warehouse_id' => $id, 'category_id' => $id, 'brand_id' => $id, 'tag_id' => $id, 'search' => ['sometimes', 'string', 'max:100']];

        return [
            'sales/daily' => ['getDailySalesReport', null, [...$orders, 'date' => ['sometimes', 'date_format:Y-m-d']]],
            'sales/monthly' => ['getMonthlySalesReport', null, [...$orders, 'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'], 'month' => ['sometimes', 'integer', 'min:1', 'max:12']]],
            'sales' => ['getSalesReport', null, $orders],
            'sales/chart' => ['getSalesReportChart', null, [...$orders, 'group_by' => ['sometimes', Rule::in(['day', 'week', 'month'])]]],
            'purchases/daily' => ['getDailyPurchaseReport', 'purchasing', [...$purchases, 'date' => ['sometimes', 'date_format:Y-m-d']]],
            'purchases/monthly' => ['getMonthlyPurchaseReport', 'purchasing', [...$purchases, 'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'], 'month' => ['sometimes', 'integer', 'min:1', 'max:12']]],
            'purchases' => ['getPurchaseReport', 'purchasing', [...$range, ...$purchases]],
            'profit-loss' => ['getProfitLossReport', null, $orders],
            'profit-by-product' => ['getProfitByProduct', null, $orders],
            'profit-by-category' => ['getProfitByCategory', null, $orders],
            'profit-by-brand' => ['getProfitByBrand', null, $orders],
            'profit-by-customer' => ['getProfitByCustomer', null, $orders],
            'profit-by-location' => ['getProfitByLocation', null, $orders],
            'profit-by-invoice' => ['getProfitByInvoice', null, $orders],
            'bestsellers' => ['getBestsellerReport', null, [...$orders, 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]],
            'products' => ['getProductReport', null, [...$range, ...$stock]],
            'stock' => ['getStockReport', null, $stock],
            'product-expiry' => ['getProductExpiryReport', null, ['within_days' => ['sometimes', 'integer', 'min:0', 'max:3650'], 'warehouse_id' => $id]],
            'product-quantity-alerts' => ['getProductQuantityAlertReport', null, ['warehouse_id' => $id, 'search' => ['sometimes', 'string', 'max:100']]],
            'warehouse-stock' => ['getWarehouseStockReport', null, ['warehouse_id' => $id]],
            'warehouse-stock/chart' => ['getWarehouseStockChart', null, ['warehouse_id' => ['required', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50']]],
            'tags' => ['getTagReport', null, $orders],
            'payments' => ['getPaymentReport', null, [...$range, 'payment_method' => ['sometimes', 'string', 'max:32'], 'order_source' => ['sometimes', Rule::in(Order::SOURCES)], 'customer_id' => $id]],
            'cash-register' => ['getCashRegisterReport', 'pos', [...$range, 'warehouse_id' => $id, 'user_id' => $id]],
            'installments' => ['getInstallmentReport', 'installments', ['status' => ['sometimes', 'string', 'max:16'], 'customer_id' => $id]],
            'customers' => ['getCustomerReport', null, [...$orders, 'customer_group_id' => $id]],
            'customer-groups' => ['getCustomerGroupReport', null, $range],
            'customer-deals' => ['getCustomerDealReport', 'sales_quotations', [...$range, 'customer_id' => $id]],
            'suppliers' => ['getSupplierReport', 'purchasing', [...$range, 'supplier_id' => $id]],
            'supplier-deals' => ['getSupplierDealReport', 'purchasing', [...$range, 'supplier_id' => $id]],
            'users' => ['getUserReport', null, [...$range, 'user_id' => $id]],
            'billers' => ['getBillerReport', 'expenses', $range],
            'activity-log' => ['getActivityLogReport', null, [...$range, 'user_id' => $id, 'subject_type' => ['sometimes', 'string', 'max:64'], 'event' => ['sometimes', 'string', 'max:32']]],
            'promotions' => ['getPromotionReport', null, [...$range, 'customer_id' => $id]],
            'stock-movements' => ['getStockMovementReport', null, [...$range, 'warehouse_id' => $id, 'movement_type' => ['sometimes', 'string', 'max:32'],
                'product_id' => $id, 'group_by' => ['sometimes', Rule::in(['movement_type', 'product', 'warehouse', 'day'])]]],
        ];
    }

    /**
     * The extra feature a report needs besides advanced_reporting.
     */
    public static function featureOf(string $reportKey): ?string
    {
        return self::catalogue()[$reportKey][1] ?? null;
    }

    /**
     * Every amount in a report is in the base currency.
     */
    public function currencyCode(): string
    {
        return $this->sales->currency();
    }

    public static function permissionOf(string $reportKey): string
    {
        return 'reports.'.str_replace('/', '.', $reportKey).'.view';
    }

    /**
     * Validates the filters and runs a report for a staff user.
     *
     * @param  array<string, mixed>  $filters
     */
    public function run(string $reportKey, array $filters, User $user): ReportResult
    {
        $entry = self::catalogue()[$reportKey] ?? throw ApiException::unprocessable('report_unknown', "Unknown report [{$reportKey}].");
        $validated = Validator::make($filters, $entry[2])->validate();
        $ctx = new ReportContext($validated, $this->dashboard->scope($user), ReportConnection::name(), $this->sales->currency(),
            (string) ($this->settings->get('timezone') ?: 'UTC'));

        return $this->{$entry[0]}($ctx);
    }

    /**
     * Every row of a report, in chunks, for GenerateExport (§61.1).
     *
     * @param  array<string, mixed>  $filters
     * @return iterable<array<string, mixed>>
     */
    public function streamReport(string $reportKey, array $filters, User $user): iterable
    {
        return $this->run($reportKey, $filters, $user)->stream();
    }

    // ---- Sales ----------------------------------------------------------

    public function getDailySalesReport(ReportContext $c): ReportResult
    {
        $date = $c->string('date') ?? $c->today();
        $summary = ['date' => $date, ...$this->salesSummary($c, $c->day($date))];

        return new ReportResult(self::salesColumns(true), $summary, rows: [$summary], notes: ReportFilters::notes($c));
    }

    public function getMonthlySalesReport(ReportContext $c): ReportResult
    {
        $today = explode('-', $c->today());
        $range = $c->month($c->int('year') ?? (int) $today[0], $c->int('month') ?? (int) $today[1]);

        return new ReportResult(
            [self::col('date', 'Date', 'date'), self::col('orders', 'Orders', 'count'), self::col('net_sales', 'Net sales', 'money'), self::col('average_order_value', 'Average order value', 'money')],
            ['year' => (int) $range->from->year, 'month' => (int) $range->from->month, ...$this->salesSummary($c, $range)],
            rows: $this->salesSeries($c, $range),
            notes: ReportFilters::notes($c),
        );
    }

    /**
     * The totals, and one row per included order confirmed in the range
     * (only the lines matching product-level filters count).
     */
    public function getSalesReport(ReportContext $c): ReportResult
    {
        $range = $c->range();
        $fx = OrderQueries::FX;
        $query = $this->lines($c, $range)
            ->groupBy('o.id', 'o.order_number', 'o.confirmed_at', 'o.customer_name', 'o.order_source', 'o.payment_status', 'o.tax_amount', 'o.total',
                'o.shipping_amount', 'o.shipping_discount_amount', 'o.reward_points_discount_amount', 'o.exchange_rate_used')
            ->selectRaw('o.id, o.order_number, o.confirmed_at, o.customer_name, o.order_source, o.payment_status, SUM(oi.quantity) as units,'
                .' SUM('.OrderQueries::LINE_GROSS.') as gross, SUM('.OrderQueries::LINE_DISCOUNT.") + o.reward_points_discount_amount * {$fx} as discount,"
                ." o.tax_amount * {$fx} as tax, (o.shipping_amount - o.shipping_discount_amount) * {$fx} as shipping, o.total * {$fx} as total")
            ->orderBy('o.confirmed_at')->orderBy('o.id');

        return new ReportResult(
            [self::col('order_number', 'Order', 'text'), self::col('confirmed_at', 'Confirmed', 'datetime'), self::col('customer', 'Customer', 'text'),
                self::col('order_source', 'Source', 'text'), self::col('units', 'Units', 'quantity'), self::col('gross_sales', 'Gross', 'money'),
                self::col('discounts', 'Discounts', 'money'), self::col('tax', 'Tax', 'money'), self::col('shipping', 'Shipping', 'money'),
                self::col('total', 'Total', 'money'), self::col('payment_status', 'Payment', 'text')],
            $this->salesSummary($c, $range),
            $query,
            fn (object $r): array => [
                'order_id' => (int) $r->id, 'order_number' => $r->order_number, 'confirmed_at' => $this->local($c, $r->confirmed_at),
                'customer' => $r->customer_name, 'order_source' => $r->order_source, 'units' => ReportContext::quantity($r->units),
                'gross_sales' => ReportContext::money($r->gross), 'discounts' => ReportContext::money($r->discount), 'tax' => ReportContext::money($r->tax),
                'shipping' => ReportContext::money($r->shipping), 'total' => ReportContext::money($r->total), 'payment_status' => $r->payment_status,
            ],
            notes: ReportFilters::notes($c),
        );
    }

    public function getSalesReportChart(ReportContext $c): ReportResult
    {
        $range = $c->range($c->string('group_by') ?? 'day');
        $rows = $this->salesSeries($c, $range);

        return new ReportResult(
            [self::col('date', 'Period', 'date'), self::col('orders', 'Orders', 'count'), self::col('net_sales', 'Net sales', 'money'), self::col('average_order_value', 'Average order value', 'money')],
            ['interval' => $range->interval, ...$this->salesSummary($c, $range)],
            rows: $rows,
            chart: ['type' => 'line', 'interval' => $range->interval, 'currency' => $c->currency, 'series' => [
                ['key' => 'net_sales', 'label' => 'Net sales', 'points' => array_map(static fn (array $r): array => ['x' => $r['date'], 'y' => $r['net_sales']], $rows)],
                ['key' => 'orders', 'label' => 'Orders', 'points' => array_map(static fn (array $r): array => ['x' => $r['date'], 'y' => $r['orders']], $rows)],
            ]],
            notes: ReportFilters::notes($c),
        );
    }

    // ---- Purchases --------------------------------------------------------

    public function getDailyPurchaseReport(ReportContext $c): ReportResult
    {
        $date = $c->string('date') ?? $c->today();
        $query = $this->purchaseOrders($c, $date, $date);

        return new ReportResult(self::purchaseColumns(), ['date' => $date, ...$this->purchaseTotals($c, $date, $date)], $query, $this->purchaseRow(...));
    }

    public function getMonthlyPurchaseReport(ReportContext $c): ReportResult
    {
        $today = explode('-', $c->today());
        $range = $c->month($c->int('year') ?? (int) $today[0], $c->int('month') ?? (int) $today[1]);
        [$from, $to] = [$range->from->toDateString(), $range->to->toDateString()];
        $byDay = DB::connection($c->connection)->query()->fromSub($this->purchaseOrders($c, $from, $to), 'x')
            ->groupBy('x.order_date')->selectRaw('x.order_date, COUNT(*) as purchase_orders, SUM(x.ordered) as ordered, SUM(x.received) as received')
            ->get()->keyBy(static fn ($r): string => substr((string) $r->order_date, 0, 10));
        $rows = [];

        foreach ($range->buckets() as $day) {
            $r = $byDay->get($day);
            $rows[] = ['date' => $day, 'purchase_orders' => (int) ($r->purchase_orders ?? 0), 'ordered_value' => ReportContext::money($r->ordered ?? 0), 'received_value' => ReportContext::money($r->received ?? 0)];
        }

        return new ReportResult(
            [self::col('date', 'Date', 'date'), self::col('purchase_orders', 'Purchase orders', 'count'), self::col('ordered_value', 'Ordered', 'money'), self::col('received_value', 'Received', 'money')],
            ['year' => (int) $range->from->year, 'month' => (int) $range->from->month, ...$this->purchaseTotals($c, $from, $to)],
            rows: $rows,
        );
    }

    public function getPurchaseReport(ReportContext $c): ReportResult
    {
        [$from, $to] = $this->days($c);

        return new ReportResult(self::purchaseColumns(), $this->purchaseTotals($c, $from, $to), $this->purchaseOrders($c, $from, $to), $this->purchaseRow(...));
    }

    // ---- Profit -----------------------------------------------------------

    /**
     * Net sales as on the dashboard, and the gross profit of the lines whose
     * cost was captured at the time of sale.
     */
    public function getProfitLossReport(ReportContext $c): ReportResult
    {
        $range = $c->range();
        $s = $this->salesSummary($c, $range);
        $known = $this->lines($c, $range)->whereNotNull('oi.unit_cost_snapshot')
            ->selectRaw('SUM('.self::NET.') as revenue, SUM('.OrderQueries::LINE_COST.') as cost')->first();
        $revenue = ReportContext::money($known->revenue ?? 0);
        $summary = [
            'gross_sales' => $s['gross_sales'], 'discounts' => $s['discounts'], 'returns' => $s['returns'], 'net_sales' => $s['net_sales'],
            'revenue_with_known_cost' => $revenue, 'cost_of_goods_sold' => ReportContext::money($known->cost ?? 0),
            'gross_profit' => $s['gross_profit'], 'gross_margin_percent' => ReportContext::percent($s['gross_profit'], $revenue),
            'cost_unknown_lines' => $s['cost_unknown_lines'],
        ];

        return new ReportResult(
            [self::col('gross_sales', 'Gross sales', 'money'), self::col('discounts', 'Discounts', 'money'), self::col('returns', 'Returns', 'money'),
                self::col('net_sales', 'Net sales', 'money'), self::col('revenue_with_known_cost', 'Revenue with known cost', 'money'),
                self::col('cost_of_goods_sold', 'Cost of goods sold', 'money'), self::col('gross_profit', 'Gross profit', 'money'),
                self::col('gross_margin_percent', 'Gross margin %', 'percent'), self::col('cost_unknown_lines', 'Lines without a cost', 'count')],
            $summary,
            rows: [$summary],
            notes: [...ReportFilters::notes($c), ...($s['cost_unknown_lines'] > 0 ? ["{$s['cost_unknown_lines']} lines without a cost at the time of sale are left out of the profit."] : [])],
        );
    }

    public function getProfitByProduct(ReportContext $c): ReportResult
    {
        return $this->profitBy($c, 'Product', static fn (Builder $q) => $q->leftJoin('products as g', 'g.id', '=', 'oi.product_id')
            ->groupBy('oi.product_id', 'g.name', 'g.sku')->selectRaw('oi.product_id as group_id, COALESCE(g.name, MAX(oi.name_snapshot)) as label, g.sku as code'));
    }

    /**
     * By each line's primary category.
     */
    public function getProfitByCategory(ReportContext $c): ReportResult
    {
        return $this->profitBy($c, 'Category', static fn (Builder $q) => $q
            ->leftJoin('product_categories as gpc', static fn ($j) => $j->on('gpc.product_id', '=', 'oi.product_id')->where('gpc.is_primary', '=', true))
            ->leftJoin('categories as g', 'g.id', '=', 'gpc.category_id')
            ->groupBy('g.id', 'g.name')->selectRaw("g.id as group_id, COALESCE(g.name, 'Uncategorised') as label, NULL as code"));
    }

    public function getProfitByBrand(ReportContext $c): ReportResult
    {
        return $this->profitBy($c, 'Brand', static fn (Builder $q) => $q->leftJoin('products as gp', 'gp.id', '=', 'oi.product_id')
            ->leftJoin('brands as g', 'g.id', '=', 'gp.brand_id')
            ->groupBy('g.id', 'g.name')->selectRaw("g.id as group_id, COALESCE(g.name, 'No brand') as label, NULL as code"));
    }

    /**
     * Guest orders are grouped as one row.
     */
    public function getProfitByCustomer(ReportContext $c): ReportResult
    {
        return $this->profitBy($c, 'Customer', static fn (Builder $q) => $q->leftJoin('customers as g', 'g.id', '=', 'o.customer_id')
            ->groupBy('o.customer_id', 'g.name', 'g.email')->selectRaw("o.customer_id as group_id, COALESCE(g.name, 'Guests') as label, g.email as code"));
    }

    /**
     * By fulfilment warehouse.
     */
    public function getProfitByLocation(ReportContext $c): ReportResult
    {
        return $this->profitBy($c, 'Warehouse', static fn (Builder $q) => $q->leftJoin('warehouses as g', 'g.id', '=', 'oi.warehouse_id')
            ->groupBy('oi.warehouse_id', 'g.name', 'g.code')->selectRaw("oi.warehouse_id as group_id, COALESCE(g.name, 'No warehouse (digital)') as label, g.code as code"));
    }

    /**
     * Per order (invoice).
     */
    public function getProfitByInvoice(ReportContext $c): ReportResult
    {
        return $this->profitBy($c, 'Order', static fn (Builder $q) => $q
            ->groupBy('o.id', 'o.order_number', 'o.invoice_number')->selectRaw('o.id as group_id, o.order_number as label, o.invoice_number as code'), 'label');
    }

    // ---- Products and stock ---------------------------------------------------

    public function getBestsellerReport(ReportContext $c): ReportResult
    {
        $rows = $this->lines($c, $c->range())->whereNotNull('oi.product_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->groupBy('oi.product_id', 'p.name', 'p.sku')
            ->selectRaw('oi.product_id, p.name, p.sku, SUM(oi.quantity) as units, SUM('.self::NET.') as net_sales, COUNT(DISTINCT o.id) as orders')
            ->orderByDesc('units')->orderByDesc('net_sales')->orderBy('oi.product_id')
            ->limit($c->int('limit') ?? 20)->get()
            ->values()->map(static fn (object $r, int $i): array => [
                'rank' => $i + 1, 'product_id' => (int) $r->product_id, 'name' => $r->name, 'sku' => $r->sku, 'orders' => (int) $r->orders,
                'units' => ReportContext::quantity($r->units), 'net_sales' => ReportContext::money($r->net_sales),
            ])->all();

        return new ReportResult([self::col('rank', 'Rank', 'count'), self::col('name', 'Product', 'text'), self::col('sku', 'SKU', 'text'),
            self::col('orders', 'Orders', 'count'), self::col('units', 'Units', 'quantity'), self::col('net_sales', 'Net sales', 'money')], rows: $rows);
    }

    /**
     * Every product: units sold and revenue in the range, and stock now.
     */
    public function getProductReport(ReportContext $c): ReportResult
    {
        $sales = $this->lines($c, $c->range())->whereNotNull('oi.product_id')->groupBy('oi.product_id')
            ->selectRaw('oi.product_id, SUM(oi.quantity) as units, SUM('.self::NET.') as net_sales');
        $query = $this->products($c)
            ->leftJoinSub($sales, 'sl', 'sl.product_id', '=', 'p.id')
            ->leftJoinSub($this->stockByProduct($c), 'st', 'st.product_id', '=', 'p.id')
            ->selectRaw('p.id, p.name, p.sku, p.product_type, p.is_active, COALESCE(sl.units, 0) as units, COALESCE(sl.net_sales, 0) as net_sales,'
                .' st.on_hand, st.available')
            ->orderBy('p.name')->orderBy('p.id');

        return new ReportResult(
            [self::col('name', 'Product', 'text'), self::col('sku', 'SKU', 'text'), self::col('product_type', 'Type', 'text'),
                self::col('units_sold', 'Units sold', 'quantity'), self::col('net_sales', 'Net sales', 'money'),
                self::col('on_hand', 'On hand', 'quantity'), self::col('available', 'Available', 'quantity')],
            null,
            $query,
            static fn (object $r): array => [
                'product_id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku, 'product_type' => $r->product_type, 'is_active' => (bool) $r->is_active,
                'units_sold' => ReportContext::quantity($r->units), 'net_sales' => ReportContext::money($r->net_sales),
                // Digital, service and bundle products hold no stock of their own.
                'on_hand' => $r->on_hand === null ? null : ReportContext::quantity($r->on_hand),
                'available' => $r->available === null ? null : ReportContext::quantity($r->available),
            ],
        );
    }

    /**
     * On-hand, reserved and available stock per product or variant across
     * the active warehouses in scope, valued at the current cost price.
     */
    public function getStockReport(ReportContext $c): ReportResult
    {
        $query = $this->inventoryRows($c)
            ->groupBy('i.product_id', 'i.variant_key', 'p.name', 'p.sku', 'v.sku', 'p.cost_price', 'v.cost_price')
            ->selectRaw('i.product_id, NULLIF(i.variant_key, 0) as variant_id, p.name, COALESCE(v.sku, p.sku) as sku, SUM(i.quantity) as on_hand,'
                .' SUM(i.reserved_quantity) as reserved, SUM(i.quantity - i.reserved_quantity) as available,'
                .' SUM(i.quantity) * COALESCE(v.cost_price, p.cost_price) as stock_value, COALESCE(v.cost_price, p.cost_price) as cost_price')
            ->orderBy('p.name')->orderBy('i.product_id')->orderBy('i.variant_key');

        return new ReportResult(
            [self::col('name', 'Product', 'text'), self::col('sku', 'SKU', 'text'), self::col('on_hand', 'On hand', 'quantity'),
                self::col('reserved', 'Reserved', 'quantity'), self::col('available', 'Available', 'quantity'), self::col('stock_value', 'Value at cost', 'money')],
            null,
            $query,
            static fn (object $r): array => [
                'product_id' => (int) $r->product_id, 'product_variant_id' => $r->variant_id === null ? null : (int) $r->variant_id, 'name' => $r->name, 'sku' => $r->sku,
                'on_hand' => ReportContext::quantity($r->on_hand), 'reserved' => ReportContext::quantity($r->reserved), 'available' => ReportContext::quantity($r->available),
                'stock_value' => $r->cost_price === null ? null : ReportContext::money($r->stock_value),
            ],
        );
    }

    /**
     * Products whose expiry date is within the days given (default 30) or past.
     */
    public function getProductExpiryReport(ReportContext $c): ReportResult
    {
        $today = $c->today();
        $until = CarbonImmutable::parse($today)->addDays($c->int('within_days') ?? 30)->toDateString();
        $query = DB::connection($c->connection)->table('products as p')->whereNull('p.deleted_at')->whereNotNull('p.expiry_date')->where('p.expiry_date', '<=', $until)
            ->leftJoinSub($this->stockByProduct($c), 'st', 'st.product_id', '=', 'p.id')
            ->selectRaw('p.id, p.name, p.sku, p.expiry_date, st.on_hand, st.available')
            ->orderBy('p.expiry_date')->orderBy('p.id');

        return new ReportResult(
            [self::col('name', 'Product', 'text'), self::col('sku', 'SKU', 'text'), self::col('expiry_date', 'Expiry date', 'date'),
                self::col('days_left', 'Days left', 'count'), self::col('available', 'Available', 'quantity')],
            ['as_of' => $today, 'until' => $until],
            $query,
            static fn (object $r): array => [
                'product_id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku, 'expiry_date' => substr((string) $r->expiry_date, 0, 10),
                'days_left' => (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse((string) $r->expiry_date), false),
                'is_expired' => substr((string) $r->expiry_date, 0, 10) < $today,
                'on_hand' => $r->on_hand === null ? null : ReportContext::quantity($r->on_hand), 'available' => $r->available === null ? null : ReportContext::quantity($r->available),
            ],
        );
    }

    /**
     * InventoryService::getLowStockProducts() (§32.7) with report filtering
     * and export: the same query as the low-stock list. It reads the
     * primary, like the list it mirrors.
     */
    public function getProductQuantityAlertReport(ReportContext $c): ReportResult
    {
        $warehouses = $this->warehouses($c);
        $search = $c->string('search');

        return new ReportResult(
            [self::col('product_name', 'Product', 'text'), self::col('sku', 'SKU', 'text'), self::col('available', 'Available', 'quantity'),
                self::col('low_stock_threshold', 'Threshold', 'count')],
            map: static fn (object $r): array => [
                'product_id' => (int) $r->product_id, 'product_variant_id' => $r->product_variant_id === null ? null : (int) $r->product_variant_id,
                'product_name' => $r->product_name, 'sku' => $r->sku, 'on_hand' => ReportContext::quantity($r->quantity),
                'reserved' => ReportContext::quantity($r->reserved_quantity), 'available' => ReportContext::quantity($r->available), 'low_stock_threshold' => (int) $r->low_stock_threshold,
            ],
            pages: fn (int $page, int $perPage) => $this->inventory->getLowStockProducts($warehouses, array_filter(['search' => $search, 'per_page' => $perPage, 'page' => $page])),
        );
    }

    /**
     * Per warehouse: products held, units and value at cost.
     */
    public function getWarehouseStockReport(ReportContext $c): ReportResult
    {
        $warehouses = $this->warehouses($c);
        $query = DB::connection($c->connection)->table('warehouses as w')
            ->leftJoin('inventory as i', 'i.warehouse_id', '=', 'w.id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->when($warehouses !== null, static fn ($q) => $q->whereIn('w.id', $warehouses ?: [0]))
            ->groupBy('w.id', 'w.name', 'w.code', 'w.is_active')
            ->selectRaw('w.id, w.name, w.code, w.is_active, COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.product_id END) as products,'
                .' COALESCE(SUM(i.quantity), 0) as on_hand, COALESCE(SUM(i.reserved_quantity), 0) as reserved,'
                .' COALESCE(SUM(i.quantity * COALESCE(v.cost_price, p.cost_price)), 0) as stock_value')
            ->orderBy('w.name')->orderBy('w.id');

        return new ReportResult(
            [self::col('name', 'Warehouse', 'text'), self::col('code', 'Code', 'text'), self::col('products', 'Products in stock', 'count'),
                self::col('on_hand', 'On hand', 'quantity'), self::col('reserved', 'Reserved', 'quantity'), self::col('stock_value', 'Value at cost', 'money')],
            null,
            $query,
            static fn (object $r): array => [
                'warehouse_id' => (int) $r->id, 'name' => $r->name, 'code' => $r->code, 'is_active' => (bool) $r->is_active, 'products' => (int) $r->products,
                'on_hand' => ReportContext::quantity($r->on_hand), 'reserved' => ReportContext::quantity($r->reserved),
                'available' => bcsub(ReportContext::quantity($r->on_hand), ReportContext::quantity($r->reserved), 3), 'stock_value' => ReportContext::money($r->stock_value),
            ],
        );
    }

    /**
     * The products holding the most stock value in one warehouse.
     */
    public function getWarehouseStockChart(ReportContext $c): ReportResult
    {
        $warehouseId = (int) $c->int('warehouse_id');

        if ($c->scope->warehouses($warehouseId) === []) {
            throw ApiException::forbidden('forbidden', 'You cannot see this warehouse.');
        }

        $rows = DB::connection($c->connection)->table('inventory as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->where('i.warehouse_id', $warehouseId)->where('i.quantity', '>', 0)
            ->selectRaw('i.product_id, NULLIF(i.variant_key, 0) as variant_id, p.name, COALESCE(v.sku, p.sku) as sku, i.quantity,'
                .' i.quantity * COALESCE(v.cost_price, p.cost_price, 0) as stock_value')
            ->orderByDesc('stock_value')->orderBy('i.id')
            ->limit($c->int('limit') ?? 15)->get()
            ->map(static fn (object $r): array => [
                'product_id' => (int) $r->product_id, 'product_variant_id' => $r->variant_id === null ? null : (int) $r->variant_id,
                'name' => $r->name, 'sku' => $r->sku, 'on_hand' => ReportContext::quantity($r->quantity), 'stock_value' => ReportContext::money($r->stock_value),
            ])->all();

        return new ReportResult(
            [self::col('name', 'Product', 'text'), self::col('sku', 'SKU', 'text'), self::col('on_hand', 'On hand', 'quantity'), self::col('stock_value', 'Value at cost', 'money')],
            ['warehouse_id' => $warehouseId],
            rows: $rows,
            chart: ['type' => 'bar', 'currency' => $c->currency, 'series' => [['key' => 'stock_value', 'label' => 'Value at cost',
                'points' => array_map(static fn (array $r): array => ['x' => $r['sku'] ?? $r['name'], 'y' => $r['stock_value']], $rows)]]],
        );
    }

    /**
     * Sales rolled up by product tag; a product with several tags counts
     * under each.
     */
    public function getTagReport(ReportContext $c): ReportResult
    {
        $query = $this->lines($c, $c->range())
            ->join('product_tag as pt', 'pt.product_id', '=', 'oi.product_id')
            ->join('tags as t', 't.id', '=', 'pt.tag_id')
            ->groupBy('t.id', 't.name')
            ->selectRaw('t.id, t.name, COUNT(DISTINCT o.id) as orders, SUM(oi.quantity) as units, SUM('.self::NET.') as net_sales')
            ->orderByDesc('net_sales')->orderBy('t.id');

        return new ReportResult(
            [self::col('name', 'Tag', 'text'), self::col('orders', 'Orders', 'count'), self::col('units', 'Units', 'quantity'), self::col('net_sales', 'Net sales', 'money')],
            null,
            $query,
            static fn (object $r): array => ['tag_id' => (int) $r->id, 'name' => $r->name, 'orders' => (int) $r->orders,
                'units' => ReportContext::quantity($r->units), 'net_sales' => ReportContext::money($r->net_sales)],
        );
    }

    // ---- Payments ---------------------------------------------------------

    /**
     * Live money in and out by payment method and provider, dated by paid_at.
     */
    public function getPaymentReport(ReportContext $c): ReportResult
    {
        $rate = 'COALESCE(op.exchange_rate_used, o.exchange_rate_used, 1)';
        $query = $c->scope->orders($c->table('order_payments as op')->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('op.status', 'successful')->where('op.mode', 'live')->where('o.is_test', false)
            ->whereBetween('op.paid_at', $c->between($c->range()))
            ->when($c->string('payment_method') !== null, static fn ($q) => $q->where('op.payment_method', $c->string('payment_method')))
            ->when($c->string('order_source') !== null, static fn ($q) => $q->where('o.order_source', $c->string('order_source')))
            ->when($c->int('customer_id') !== null, static fn ($q) => $q->where('o.customer_id', $c->int('customer_id'))))
            ->groupBy('op.payment_method', 'op.provider')
            ->selectRaw("op.payment_method, op.provider, SUM(CASE WHEN op.kind = 'payment' THEN 1 ELSE 0 END) as payments,"
                ." SUM(CASE WHEN op.kind = 'payment' THEN op.amount_paid * {$rate} ELSE 0 END) as received,"
                ." SUM(CASE WHEN op.kind IN ('refund', 'chargeback') THEN -op.amount_paid * {$rate} ELSE 0 END) as refunded")
            ->orderBy('op.payment_method')->orderBy('op.provider');

        return new ReportResult(
            [self::col('payment_method', 'Method', 'text'), self::col('provider', 'Provider', 'text'), self::col('payments', 'Payments', 'count'),
                self::col('received', 'Received', 'money'), self::col('refunded', 'Refunded', 'money'), self::col('net', 'Net', 'money')],
            null,
            $query,
            static fn (object $r): array => [
                'payment_method' => $r->payment_method, 'provider' => $r->provider, 'payments' => (int) $r->payments,
                'received' => ReportContext::money($r->received), 'refunded' => ReportContext::money($r->refunded),
                'net' => bcsub(ReportContext::money($r->received), ReportContext::money($r->refunded), 4),
            ],
        );
    }

    /**
     * Closed POS sessions in the range: expected against counted cash.
     */
    public function getCashRegisterReport(ReportContext $c): ReportResult
    {
        $warehouses = $this->warehouses($c);
        $query = $c->table('pos_sessions as s')->join('pos_registers as r', 'r.id', '=', 's.pos_register_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 's.opened_by_user_id')
            ->where('s.status', 'closed')->whereBetween('s.closed_at', $c->between($c->range()))
            ->when($warehouses !== null, static fn ($q) => $q->whereIn('r.warehouse_id', $warehouses ?: [0]))
            ->when($c->int('user_id') !== null, static fn ($q) => $q->where('s.opened_by_user_id', $c->int('user_id')))
            ->selectRaw('s.id, r.name as register, w.name as warehouse, u.name as opened_by, s.opened_at, s.closed_at, s.opening_cash_float,'
                .' s.expected_cash, s.closing_cash_float, s.cash_variance')
            ->orderBy('s.closed_at')->orderBy('s.id');

        return new ReportResult(
            [self::col('register', 'Register', 'text'), self::col('warehouse', 'Warehouse', 'text'), self::col('opened_by', 'Opened by', 'text'),
                self::col('opened_at', 'Opened', 'datetime'), self::col('closed_at', 'Closed', 'datetime'), self::col('opening_float', 'Opening float', 'money'),
                self::col('expected_cash', 'Expected cash', 'money'), self::col('counted_cash', 'Counted cash', 'money'),
                self::col('variance', 'Over (+) / short (−)', 'money'), self::col('result', 'Result', 'text')],
            null,
            $query,
            fn (object $r): array => [
                'session_id' => (int) $r->id, 'register' => $r->register, 'warehouse' => $r->warehouse, 'opened_by' => $r->opened_by,
                'opened_at' => $this->local($c, $r->opened_at), 'closed_at' => $this->local($c, $r->closed_at),
                'opening_float' => ReportContext::money($r->opening_cash_float), 'expected_cash' => ReportContext::money($r->expected_cash),
                'counted_cash' => ReportContext::money($r->closing_cash_float), 'variance' => ReportContext::money($r->cash_variance),
                'result' => match (bccomp(ReportContext::money($r->cash_variance), '0', 4)) {
                    1 => 'over', -1 => 'short', default => 'balanced'
                },
            ],
        );
    }

    public function getInstallmentReport(ReportContext $c): ReportResult
    {
        $payments = $c->table('installment_payments')->groupBy('installment_plan_id')
            ->selectRaw("installment_plan_id, SUM(amount_paid) as paid, SUM(CASE WHEN status != 'paid' THEN amount_due - amount_paid ELSE 0 END) as outstanding,"
                ." MIN(CASE WHEN status != 'paid' THEN due_date END) as next_due, SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,"
                .' COUNT(*) as installments');
        $query = $c->scope->orders($c->table('installment_plans as ip')->join('orders as o', 'o.id', '=', 'ip.order_id')
            ->leftJoinSub($payments, 'pp', 'pp.installment_plan_id', '=', 'ip.id')
            ->when($c->string('status') !== null, static fn ($q) => $q->where('ip.status', $c->string('status')))
            ->when($c->int('customer_id') !== null, static fn ($q) => $q->where('o.customer_id', $c->int('customer_id'))))
            ->selectRaw('ip.id, o.order_number, o.customer_name, ip.status, ip.frequency, ip.currency_code, ip.total_amount, pp.paid, pp.outstanding, pp.next_due, pp.overdue, pp.installments')
            ->orderByDesc('ip.id');

        return new ReportResult(
            [self::col('order_number', 'Order', 'text'), self::col('customer', 'Customer', 'text'), self::col('status', 'Status', 'text'),
                self::col('total_amount', 'Total', 'money'), self::col('paid', 'Paid', 'money'), self::col('outstanding', 'Outstanding', 'money'),
                self::col('next_due_date', 'Next due', 'date'), self::col('overdue_installments', 'Overdue', 'count'), self::col('currency_code', 'Currency', 'text')],
            null,
            $query,
            static fn (object $r): array => [
                'plan_id' => (int) $r->id, 'order_number' => $r->order_number, 'customer' => $r->customer_name, 'status' => $r->status, 'frequency' => $r->frequency,
                'installments' => (int) ($r->installments ?? 0), 'total_amount' => ReportContext::money($r->total_amount), 'paid' => ReportContext::money($r->paid),
                'outstanding' => ReportContext::money($r->outstanding), 'next_due_date' => $r->next_due === null ? null : substr((string) $r->next_due, 0, 10),
                'overdue_installments' => (int) ($r->overdue ?? 0), 'currency_code' => $r->currency_code,
            ],
        );
    }

    // ---- Customers ----------------------------------------------------------

    /**
     * Customers who bought in the range (guests are left out).
     */
    public function getCustomerReport(ReportContext $c): ReportResult
    {
        $query = $this->lines($c, $c->range())->whereNotNull('o.customer_id')
            ->join('customers as cu', 'cu.id', '=', 'o.customer_id')
            ->when($c->int('customer_group_id') !== null, static fn ($q) => $q->where('cu.customer_group_id', $c->int('customer_group_id')))
            ->groupBy('o.customer_id', 'cu.name', 'cu.email')
            ->selectRaw('o.customer_id, cu.name, cu.email, COUNT(DISTINCT o.id) as orders, SUM(oi.quantity) as units, SUM('.self::NET.') as net_sales, MAX(o.confirmed_at) as last_order_at')
            ->orderByDesc('net_sales')->orderBy('o.customer_id');

        return new ReportResult(
            [self::col('name', 'Customer', 'text'), self::col('email', 'Email', 'text'), self::col('orders', 'Orders', 'count'), self::col('units', 'Units', 'quantity'),
                self::col('net_sales', 'Net sales', 'money'), self::col('average_order_value', 'Average order value', 'money'), self::col('last_order_at', 'Last order', 'datetime')],
            null,
            $query,
            fn (object $r): array => [
                'customer_id' => (int) $r->customer_id, 'name' => $r->name, 'email' => $r->email, 'orders' => (int) $r->orders,
                'units' => ReportContext::quantity($r->units), 'net_sales' => ReportContext::money($r->net_sales),
                'average_order_value' => (int) $r->orders === 0 ? '0.0000' : bcdiv(ReportContext::money($r->net_sales), (string) $r->orders, 4),
                'last_order_at' => $this->local($c, $r->last_order_at),
            ],
        );
    }

    public function getCustomerGroupReport(ReportContext $c): ReportResult
    {
        $sales = $this->lines($c, $c->range())->join('customers as cu', 'cu.id', '=', 'o.customer_id')->groupBy('cu.customer_group_id')
            ->selectRaw('cu.customer_group_id, COUNT(DISTINCT o.customer_id) as buyers, COUNT(DISTINCT o.id) as orders, SUM('.self::NET.') as net_sales');
        $members = $c->table('customers')->whereNull('deleted_at')->groupBy('customer_group_id')->selectRaw('customer_group_id, COUNT(*) as members');
        $query = $c->table('customer_groups as g')
            ->leftJoinSub($members, 'm', 'm.customer_group_id', '=', 'g.id')
            ->leftJoinSub($sales, 's', 's.customer_group_id', '=', 'g.id')
            ->selectRaw('g.id, g.name, g.is_default, COALESCE(m.members, 0) as members, COALESCE(s.buyers, 0) as buyers, COALESCE(s.orders, 0) as orders, COALESCE(s.net_sales, 0) as net_sales')
            ->orderByDesc('net_sales')->orderBy('g.id');

        return new ReportResult(
            [self::col('name', 'Group', 'text'), self::col('members', 'Members', 'count'), self::col('buyers', 'Buying customers', 'count'),
                self::col('orders', 'Orders', 'count'), self::col('net_sales', 'Net sales', 'money')],
            null,
            $query,
            static fn (object $r): array => ['customer_group_id' => (int) $r->id, 'name' => $r->name, 'is_default' => (bool) $r->is_default,
                'members' => (int) $r->members, 'buyers' => (int) $r->buyers, 'orders' => (int) $r->orders, 'net_sales' => ReportContext::money($r->net_sales)],
        );
    }

    /**
     * Accepted quotations (§53): the quoted total against today's list
     * prices, both in the quotation's currency (list prices converted at
     * the converted order's rate).
     */
    public function getCustomerDealReport(ReportContext $c): ReportResult
    {
        $lines = $c->table('sales_quotation_items as qi')
            ->join('sales_quotation_request_items as ri', 'ri.id', '=', 'qi.sales_quotation_request_item_id')
            ->join('products as p', 'p.id', '=', 'ri.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'ri.product_variant_id')
            ->groupBy('qi.sales_quotation_id')
            ->selectRaw('qi.sales_quotation_id, COUNT(*) as lines, SUM(ri.quantity_requested * COALESCE(v.price, p.price)) as list_base');
        $query = $c->table('sales_quotations as q')
            ->join('sales_quotation_requests as r', 'r.id', '=', 'q.sales_quotation_request_id')
            ->leftJoin('customers as cu', 'cu.id', '=', 'r.customer_id')
            ->leftJoin('orders as co', 'co.id', '=', 'q.converted_order_id')
            ->leftJoinSub($lines, 'ql', 'ql.sales_quotation_id', '=', 'q.id')
            ->where('q.status', 'accepted')->whereBetween('q.responded_at', $c->between($c->range()))
            ->when($c->int('customer_id') !== null, static fn ($q) => $q->where('r.customer_id', $c->int('customer_id')))
            ->selectRaw('q.id, q.quotation_number, q.responded_at, q.currency_code, q.subtotal - q.discount_amount as quoted, cu.id as customer_id, cu.name as customer,'
                .' co.order_number, COALESCE(ql.lines, 0) as lines, COALESCE(ql.list_base, 0) / COALESCE(co.exchange_rate_used, 1) as list_total')
            ->orderBy('q.responded_at')->orderBy('q.id');

        return new ReportResult(
            [self::col('quotation_number', 'Quotation', 'text'), self::col('customer', 'Customer', 'text'), self::col('accepted_at', 'Accepted', 'datetime'),
                self::col('order_number', 'Order', 'text'), self::col('list_total', 'List price', 'money'), self::col('quoted_total', 'Quoted', 'money'),
                self::col('difference', 'Difference', 'money'), self::col('discount_percent', 'Below list %', 'percent'), self::col('currency_code', 'Currency', 'text')],
            null,
            $query,
            function (object $r) use ($c): array {
                $list = ReportContext::money($r->list_total);
                $quoted = ReportContext::money($r->quoted);

                return [
                    'quotation_id' => (int) $r->id, 'quotation_number' => $r->quotation_number, 'customer_id' => $r->customer_id === null ? null : (int) $r->customer_id,
                    'customer' => $r->customer, 'accepted_at' => $this->local($c, $r->responded_at), 'order_number' => $r->order_number, 'lines' => (int) $r->lines,
                    'list_total' => $list, 'quoted_total' => $quoted, 'difference' => bcsub($list, $quoted, 4),
                    'discount_percent' => ReportContext::percent(bcsub($list, $quoted, 4), $list), 'currency_code' => $r->currency_code,
                ];
            },
        );
    }

    // ---- Suppliers ----------------------------------------------------------

    public function getSupplierReport(ReportContext $c): ReportResult
    {
        [$from, $to] = $this->days($c);
        $query = DB::connection($c->connection)->query()->fromSub($this->purchaseOrders($c, $from, $to), 'x')
            ->groupBy('x.supplier_id', 'x.supplier')
            ->selectRaw('x.supplier_id, x.supplier, COUNT(*) as purchase_orders, SUM(x.ordered) as ordered, SUM(x.received) as received, SUM(x.paid) as paid')
            ->orderByDesc('ordered')->orderBy('x.supplier_id');

        return new ReportResult(
            [self::col('supplier', 'Supplier', 'text'), self::col('purchase_orders', 'Purchase orders', 'count'), self::col('ordered_value', 'Ordered', 'money'),
                self::col('received_value', 'Received', 'money'), self::col('paid', 'Paid', 'money'), self::col('outstanding', 'Outstanding', 'money')],
            $this->purchaseTotals($c, $from, $to),
            $query,
            static fn (object $r): array => [
                'supplier_id' => (int) $r->supplier_id, 'supplier' => $r->supplier, 'purchase_orders' => (int) $r->purchase_orders,
                'ordered_value' => ReportContext::money($r->ordered), 'received_value' => ReportContext::money($r->received), 'paid' => ReportContext::money($r->paid),
                'outstanding' => bcsub(ReportContext::money($r->ordered), ReportContext::money($r->paid), 4),
            ],
        );
    }

    /**
     * The agreed supplier cost against what purchase orders in the range
     * actually paid per unit (base currency).
     */
    public function getSupplierDealReport(ReportContext $c): ReportResult
    {
        [$from, $to] = $this->days($c);
        $bought = $c->table('purchase_order_items as i')->join('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->whereNotIn('po.status', ['draft', 'cancelled'])->whereBetween('po.order_date', [$from, $to])
            ->groupBy('po.supplier_id', 'i.product_id')
            ->selectRaw('po.supplier_id, i.product_id, SUM(i.quantity_ordered) as units, SUM(i.quantity_ordered * i.unit_cost * COALESCE(po.exchange_rate_used, 1)) as spent,'
                .' MIN(i.unit_cost * COALESCE(po.exchange_rate_used, 1)) as min_cost, MAX(i.unit_cost * COALESCE(po.exchange_rate_used, 1)) as max_cost');
        $query = $c->table('supplier_products as sp')
            ->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->join('products as p', 'p.id', '=', 'sp.product_id')
            ->leftJoinSub($bought, 'b', static fn ($j) => $j->on('b.supplier_id', '=', 'sp.supplier_id')->on('b.product_id', '=', 'sp.product_id'))
            ->when($c->int('supplier_id') !== null, static fn ($q) => $q->where('sp.supplier_id', $c->int('supplier_id')))
            ->selectRaw('sp.supplier_id, s.name as supplier, sp.product_id, p.name as product, sp.supplier_sku, sp.cost_price, b.units, b.spent, b.min_cost, b.max_cost')
            ->orderBy('s.name')->orderBy('p.name')->orderBy('sp.id');

        return new ReportResult(
            [self::col('supplier', 'Supplier', 'text'), self::col('product', 'Product', 'text'), self::col('supplier_sku', 'Supplier SKU', 'text'),
                self::col('agreed_cost', 'Agreed cost', 'money'), self::col('units_bought', 'Units bought', 'quantity'), self::col('average_cost', 'Average paid', 'money'),
                self::col('min_cost', 'Lowest paid', 'money'), self::col('max_cost', 'Highest paid', 'money'), self::col('variance_percent', 'Above agreed %', 'percent')],
            ['from' => $from, 'to' => $to],
            $query,
            static function (object $r): array {
                $units = ReportContext::quantity($r->units);
                $average = $r->units === null || bccomp($units, '0', 3) === 0 ? null : bcdiv(ReportContext::money($r->spent), $units, 4);
                $agreed = $r->cost_price === null ? null : ReportContext::money($r->cost_price);

                return [
                    'supplier_id' => (int) $r->supplier_id, 'supplier' => $r->supplier, 'product_id' => (int) $r->product_id, 'product' => $r->product,
                    'supplier_sku' => $r->supplier_sku, 'agreed_cost' => $agreed, 'units_bought' => $units, 'average_cost' => $average,
                    'min_cost' => $r->min_cost === null ? null : ReportContext::money($r->min_cost), 'max_cost' => $r->max_cost === null ? null : ReportContext::money($r->max_cost),
                    'variance_percent' => $average === null || $agreed === null ? null : ReportContext::percent(bcsub($average, $agreed, 4), $agreed),
                ];
            },
        );
    }

    // ---- People -------------------------------------------------------------

    /**
     * Per staff user: included orders they created in the range, split into
     * back-office orders and POS sales, and their net sales.
     */
    public function getUserReport(ReportContext $c): ReportResult
    {
        $sales = $this->lines($c, $c->range())->whereNotNull('o.created_by_user_id')->groupBy('o.created_by_user_id')
            ->selectRaw("o.created_by_user_id, COUNT(DISTINCT CASE WHEN o.order_source = 'pos' THEN o.id END) as pos_sales,"
                ." COUNT(DISTINCT CASE WHEN o.order_source != 'pos' THEN o.id END) as orders_created, SUM(".self::NET.') as net_sales');
        $query = $c->table('users as u')
            ->leftJoinSub($sales, 's', 's.created_by_user_id', '=', 'u.id')
            ->when($c->int('user_id') !== null, static fn ($q) => $q->where('u.id', $c->int('user_id')))
            ->selectRaw('u.id, u.name, u.email, u.is_active, COALESCE(s.orders_created, 0) as orders_created, COALESCE(s.pos_sales, 0) as pos_sales, COALESCE(s.net_sales, 0) as net_sales')
            ->orderByDesc('net_sales')->orderBy('u.id');

        return new ReportResult(
            [self::col('name', 'Staff', 'text'), self::col('orders_created', 'Orders created', 'count'), self::col('pos_sales', 'POS sales', 'count'), self::col('net_sales', 'Net sales', 'money')],
            null,
            $query,
            static fn (object $r): array => ['user_id' => (int) $r->id, 'name' => $r->name, 'email' => $r->email, 'is_active' => (bool) $r->is_active,
                'orders_created' => (int) $r->orders_created, 'pos_sales' => (int) $r->pos_sales, 'net_sales' => ReportContext::money($r->net_sales)],
        );
    }

    /**
     * Expenses per biller (an expense payee, Assumption A-53), dated by
     * expense_date, in the base currency.
     */
    public function getBillerReport(ReportContext $c): ReportResult
    {
        [$from, $to] = $this->days($c);
        $query = $c->table('billers as b')
            ->leftJoin('expenses as e', static fn ($j) => $j->on('e.biller_id', '=', 'b.id')->whereBetween('e.expense_date', [$from, $to]))
            ->groupBy('b.id', 'b.name', 'b.category')
            ->selectRaw('b.id, b.name, b.category, COUNT(e.id) as expenses, COALESCE(SUM(e.amount * COALESCE(e.exchange_rate_used, 1)), 0) as total,'
                ." COALESCE(SUM(CASE WHEN e.status = 'paid' THEN e.amount * COALESCE(e.exchange_rate_used, 1) ELSE 0 END), 0) as paid")
            ->orderByDesc('total')->orderBy('b.id');

        return new ReportResult(
            [self::col('name', 'Biller', 'text'), self::col('category', 'Category', 'text'), self::col('expenses', 'Expenses', 'count'),
                self::col('total', 'Total', 'money'), self::col('paid', 'Paid', 'money'), self::col('unpaid', 'Unpaid', 'money')],
            ['from' => $from, 'to' => $to],
            $query,
            static fn (object $r): array => ['biller_id' => (int) $r->id, 'name' => $r->name, 'category' => $r->category, 'expenses' => (int) $r->expenses,
                'total' => ReportContext::money($r->total), 'paid' => ReportContext::money($r->paid), 'unpaid' => bcsub(ReportContext::money($r->total), ReportContext::money($r->paid), 4)],
        );
    }

    // ---- Activity -----------------------------------------------------------

    public function getActivityLogReport(ReportContext $c): ReportResult
    {
        $query = $c->table('activity_log as a')
            ->leftJoin('users as u', static fn ($j) => $j->on('u.id', '=', 'a.causer_id')->where('a.causer_type', '=', 'user'))
            ->whereBetween('a.created_at', $c->between($c->range()))
            ->when($c->int('user_id') !== null, static fn ($q) => $q->where('a.causer_type', 'user')->where('a.causer_id', $c->int('user_id')))
            ->when($c->string('subject_type') !== null, static fn ($q) => $q->where('a.subject_type', $c->string('subject_type')))
            ->when($c->string('event') !== null, static fn ($q) => $q->where('a.event', $c->string('event')))
            ->selectRaw('a.id, a.created_at, a.log_name, a.description, a.event, a.subject_type, a.subject_id, a.causer_type, a.causer_id, u.name as user_name')
            ->orderByDesc('a.created_at')->orderByDesc('a.id');

        return new ReportResult(
            [self::col('created_at', 'When', 'datetime'), self::col('user', 'By', 'text'), self::col('log_name', 'Area', 'text'), self::col('description', 'What', 'text'),
                self::col('subject_type', 'Record type', 'text'), self::col('subject_id', 'Record', 'text')],
            null,
            $query,
            fn (object $r): array => [
                'id' => (int) $r->id, 'created_at' => $this->local($c, $r->created_at), 'user' => $r->user_name ?? ($r->causer_type === null ? 'System' : $r->causer_type),
                'user_id' => $r->causer_type === 'user' ? (int) $r->causer_id : null, 'log_name' => $r->log_name, 'description' => $r->description, 'event' => $r->event,
                'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id === null ? null : (string) $r->subject_id,
            ],
        );
    }

    // ---- Promotions ---------------------------------------------------------

    /**
     * Per promotion and coupon code: redemptions by status in the range,
     * the discount given and the net revenue of the orders they committed on.
     */
    public function getPromotionReport(ReportContext $c): ReportResult
    {
        $orderNet = OrderQueries::includedLines(MetricsScope::all(), $c->connection)->groupBy('o.id')->selectRaw('o.id as order_id, SUM('.self::NET.') as net');
        $query = $c->scope->orders($c->table('promotion_redemptions as r')->join('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoinSub($orderNet, 'onet', 'onet.order_id', '=', 'r.order_id')
            ->where('o.is_test', false)->whereBetween('r.created_at', $c->between($c->range()))
            ->when($c->int('customer_id') !== null, static fn ($q) => $q->where('r.customer_id', $c->int('customer_id'))))
            ->groupBy('r.promotion_id', 'r.promotion_name_snapshot', 'r.coupon_code_snapshot')
            ->selectRaw("r.promotion_id, r.promotion_name_snapshot as promotion, r.coupon_code_snapshot as coupon_code,
                SUM(CASE WHEN r.status = 'reserved' THEN 1 ELSE 0 END) as reserved, SUM(CASE WHEN r.status = 'committed' THEN 1 ELSE 0 END) as committed,
                SUM(CASE WHEN r.status = 'released' THEN 1 ELSE 0 END) as released, SUM(CASE WHEN r.status = 'reversed' THEN 1 ELSE 0 END) as reversed,
                SUM(CASE WHEN r.status = 'committed' THEN r.base_discount_amount ELSE 0 END) as discount_given,
                COUNT(DISTINCT CASE WHEN r.status = 'committed' THEN r.order_id END) as orders,
                SUM(CASE WHEN r.status = 'committed' THEN COALESCE(onet.net, 0) ELSE 0 END) as net_revenue")
            ->orderByDesc('discount_given')->orderBy('r.promotion_id')->orderBy('r.coupon_code_snapshot');

        return new ReportResult(
            [self::col('promotion', 'Promotion', 'text'), self::col('coupon_code', 'Coupon', 'text'), self::col('committed', 'Used', 'count'),
                self::col('reserved', 'Reserved', 'count'), self::col('released', 'Released', 'count'), self::col('reversed', 'Reversed', 'count'),
                self::col('discount_given', 'Discount given', 'money'), self::col('orders', 'Orders', 'count'), self::col('net_revenue', 'Net revenue of those orders', 'money')],
            null,
            $query,
            static fn (object $r): array => [
                'promotion_id' => $r->promotion_id === null ? null : (int) $r->promotion_id, 'promotion' => $r->promotion, 'coupon_code' => $r->coupon_code,
                'reserved' => (int) $r->reserved, 'committed' => (int) $r->committed, 'released' => (int) $r->released, 'reversed' => (int) $r->reversed,
                'discount_given' => ReportContext::money($r->discount_given), 'orders' => (int) $r->orders, 'net_revenue' => ReportContext::money($r->net_revenue),
            ],
        );
    }

    // ---- Inventory ----------------------------------------------------------

    /**
     * The movement ledger (§32.8) grouped by movement type (default),
     * product, warehouse or day, with the value where the cost is known.
     */
    public function getStockMovementReport(ReportContext $c): ReportResult
    {
        $warehouses = $this->warehouses($c);
        $groupBy = $c->string('group_by') ?? 'movement_type';
        $offset = CarbonImmutable::now($c->timezone)->format('P');
        [$key, $label, $joins] = match ($groupBy) {
            'product' => ['m.product_id', 'p.name', true],
            'warehouse' => ['m.warehouse_id', 'w.name', false],
            'day' => ["DATE(CONVERT_TZ(m.created_at, '+00:00', '{$offset}'))", "DATE(CONVERT_TZ(m.created_at, '+00:00', '{$offset}'))", false],
            default => ['m.movement_type', 'm.movement_type', false],
        };
        $query = $c->table('inventory_movements as m')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->when($joins, static fn ($q) => $q->leftJoin('products as p', 'p.id', '=', 'm.product_id'))
            ->whereBetween('m.created_at', $c->between($c->range()))
            ->when($warehouses !== null, static fn ($q) => $q->whereIn('m.warehouse_id', $warehouses ?: [0]))
            ->when($c->string('movement_type') !== null, static fn ($q) => $q->where('m.movement_type', $c->string('movement_type')))
            ->when($c->int('product_id') !== null, static fn ($q) => $q->where('m.product_id', $c->int('product_id')))
            ->groupByRaw("{$key}, {$label}")
            ->selectRaw("{$key} as group_key, {$label} as label, COUNT(*) as movements,"
                .' SUM(CASE WHEN m.quantity_delta > 0 THEN m.quantity_delta ELSE 0 END) as quantity_in,'
                .' SUM(CASE WHEN m.quantity_delta < 0 THEN -m.quantity_delta ELSE 0 END) as quantity_out,'
                .' SUM(CASE WHEN m.unit_cost_snapshot IS NOT NULL THEN m.quantity_delta * m.unit_cost_snapshot ELSE 0 END) as value,'
                .' SUM(CASE WHEN m.unit_cost_snapshot IS NULL AND m.quantity_delta != 0 THEN 1 ELSE 0 END) as cost_unknown')
            ->orderByRaw('group_key');

        return new ReportResult(
            [self::col('label', ucfirst(str_replace('_', ' ', $groupBy)), 'text'), self::col('movements', 'Movements', 'count'), self::col('quantity_in', 'In', 'quantity'),
                self::col('quantity_out', 'Out', 'quantity'), self::col('net_quantity', 'Net', 'quantity'), self::col('value', 'Value (known cost)', 'money'),
                self::col('cost_unknown', 'Movements without a cost', 'count')],
            ['group_by' => $groupBy],
            $query,
            static fn (object $r): array => [
                'key' => (string) $r->group_key, 'label' => (string) $r->label, 'movements' => (int) $r->movements,
                'quantity_in' => ReportContext::quantity($r->quantity_in), 'quantity_out' => ReportContext::quantity($r->quantity_out),
                'net_quantity' => bcsub(ReportContext::quantity($r->quantity_in), ReportContext::quantity($r->quantity_out), 3),
                'value' => ReportContext::money($r->value), 'cost_unknown' => (int) $r->cost_unknown,
            ],
            notes: $groupBy === 'day' ? ['Days use the store\'s current UTC offset.'] : [],
        );
    }

    // ---- Stock helpers -------------------------------------------------------

    /**
     * Products (not deleted) filtered by category, brand, tag and search.
     */
    private function products(ReportContext $c): Builder
    {
        $search = $c->string('search');

        return $c->table('products as p')->whereNull('p.deleted_at')
            ->when($c->int('category_id') !== null, static fn ($q) => $q->whereExists(static fn ($e) => $e->from('product_categories as fpc')
                ->whereColumn('fpc.product_id', 'p.id')->where('fpc.category_id', $c->int('category_id'))))
            ->when($c->int('brand_id') !== null, static fn ($q) => $q->where('p.brand_id', $c->int('brand_id')))
            ->when($c->int('tag_id') !== null, static fn ($q) => $q->whereExists(static fn ($e) => $e->from('product_tag as fpt')
                ->whereColumn('fpt.product_id', 'p.id')->where('fpt.tag_id', $c->int('tag_id'))))
            ->when($search !== null, static fn ($q) => $q->where(static fn ($w) => $w->where('p.name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('p.sku', $search)));
    }

    /**
     * Inventory rows of the active warehouses in scope, of the filtered products.
     */
    private function inventoryRows(ReportContext $c): Builder
    {
        $warehouses = $this->warehouses($c);

        return $this->products($c)
            ->join('inventory as i', 'i.product_id', '=', 'p.id')
            ->join('warehouses as w', static fn ($j) => $j->on('w.id', '=', 'i.warehouse_id')->where('w.is_active', '=', true))
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->when($warehouses !== null, static fn ($q) => $q->whereIn('i.warehouse_id', $warehouses ?: [0]));
    }

    /**
     * Stock per product across the active warehouses in scope.
     */
    private function stockByProduct(ReportContext $c): Builder
    {
        $warehouses = $this->warehouses($c);

        return $c->table('inventory as si')
            ->join('warehouses as sw', static fn ($j) => $j->on('sw.id', '=', 'si.warehouse_id')->where('sw.is_active', '=', true))
            ->when($warehouses !== null, static fn ($q) => $q->whereIn('si.warehouse_id', $warehouses ?: [0]))
            ->groupBy('si.product_id')
            ->selectRaw('si.product_id, SUM(si.quantity) as on_hand, SUM(si.quantity - si.reserved_quantity) as available');
    }

    // ---- Helpers (sales, purchases, profit) --------------------------------

    /**
     * @return array<string, mixed>
     */
    private function salesSummary(ReportContext $c, DateRange $range): array
    {
        return $this->sales->summary($range, $c->scope, ReportFilters::orders($c), $c->connection);
    }

    /**
     * Net sales and orders per bucket, with the §44.2 definitions.
     *
     * @return list<array{date: string, orders: int, net_sales: string, average_order_value: string}>
     */
    private function salesSeries(ReportContext $c, DateRange $range): array
    {
        $f = ReportFilters::orders($c);
        $fx = OrderQueries::FX;
        $lines = TimeSeries::aggregate($f(OrderQueries::includedLines($c->scope, $c->connection), true), 'o.confirmed_at', $range, 'SUM('.self::NET.')')[''] ?? [];
        $orders = TimeSeries::aggregate($f(OrderQueries::included($c->scope, $c->connection), false), 'o.confirmed_at', $range, 'COUNT(*)')[''] ?? [];
        $points = TimeSeries::aggregate($f(OrderQueries::included($c->scope, $c->connection), false), 'o.confirmed_at', $range, "SUM(o.reward_points_discount_amount * {$fx})")[''] ?? [];
        $returns = TimeSeries::aggregate($f(OrderQueries::returns($c->scope, $c->connection), false), 'op.paid_at', $range, OrderQueries::RETURNS_SUM)[''] ?? [];
        $rows = [];

        foreach ($range->buckets() as $bucket) {
            $count = (int) ($orders[$bucket] ?? 0);
            $lineNet = ReportContext::money($lines[$bucket] ?? 0);
            $rows[] = [
                'date' => $bucket,
                'orders' => $count,
                'net_sales' => bcsub(bcsub($lineNet, ReportContext::money($points[$bucket] ?? 0), 4), ReportContext::money($returns[$bucket] ?? 0), 4),
                'average_order_value' => $count === 0 ? '0.0000' : bcdiv($lineNet, (string) $count, 4),
            ];
        }

        return $rows;
    }

    /**
     * Filtered lines of included orders confirmed in the range.
     */
    private function lines(ReportContext $c, DateRange $range): Builder
    {
        return ReportFilters::orders($c)(OrderQueries::includedLines($c->scope, $c->connection), true)->whereBetween('o.confirmed_at', $c->between($range));
    }

    /**
     * @param  callable(Builder): Builder  $group  joins, groups and selects group_id, label, code
     */
    private function profitBy(ReportContext $c, string $label, callable $group, string $order = 'profit'): ReportResult
    {
        $query = $group($this->lines($c, $c->range()))
            ->selectRaw('SUM(oi.quantity) as units, SUM('.self::NET.') as revenue,'
                .' SUM(CASE WHEN oi.unit_cost_snapshot IS NOT NULL THEN '.OrderQueries::LINE_COST.' ELSE 0 END) as cost,'
                .' SUM(CASE WHEN oi.unit_cost_snapshot IS NOT NULL THEN '.self::NET.' ELSE 0 END) as revenue_known,'
                .' '.self::PROFIT.' as profit, SUM(CASE WHEN oi.unit_cost_snapshot IS NULL THEN 1 ELSE 0 END) as cost_unknown_lines');
        $order === 'profit' ? $query->orderByDesc('profit')->orderBy('group_id') : $query->orderBy($order)->orderBy('group_id');

        return new ReportResult(
            [self::col('label', $label, 'text'), self::col('code', $label === 'Order' ? 'Invoice' : ($label === 'Customer' ? 'Email' : 'Code'), 'text'), self::col('units', 'Units', 'quantity'),
                self::col('revenue', 'Revenue', 'money'), self::col('cost', 'Cost', 'money'), self::col('profit', 'Gross profit', 'money'),
                self::col('margin_percent', 'Margin %', 'percent'), self::col('cost_unknown_lines', 'Lines without a cost', 'count')],
            null,
            $query,
            static fn (object $r): array => [
                'id' => $r->group_id === null ? null : (int) $r->group_id, 'label' => $r->label, 'code' => $r->code,
                'units' => ReportContext::quantity($r->units), 'revenue' => ReportContext::money($r->revenue), 'cost' => ReportContext::money($r->cost),
                'profit' => ReportContext::money($r->profit), 'margin_percent' => ReportContext::percent(ReportContext::money($r->profit), ReportContext::money($r->revenue_known)),
                'cost_unknown_lines' => (int) $r->cost_unknown_lines,
            ],
        );
    }

    /**
     * Purchase orders sent to suppliers (not drafts or cancelled), dated by
     * order_date, with base-currency values.
     */
    private function purchaseOrders(ReportContext $c, string $from, string $to): Builder
    {
        $items = $db->table('purchase_order_items')->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM(quantity_ordered * unit_cost) as ordered, SUM(quantity_received * unit_cost) as received');
        $paid = $db->table('supplier_payments')->whereNotNull('purchase_order_id')->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM(amount_paid) as paid');
        $warehouse = $c->int('warehouse_id');

        return $db->table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'po.warehouse_id')
            ->leftJoinSub($items, 'it', 'it.purchase_order_id', '=', 'po.id')
            ->leftJoinSub($paid, 'pd', 'pd.purchase_order_id', '=', 'po.id')
            ->whereNotIn('po.status', ['draft', 'cancelled'])
            ->whereBetween('po.order_date', [$from, $to])
            ->when($c->scope->isNarrowed() || $warehouse !== null, static fn ($q) => $q->whereIn('po.warehouse_id', $c->scope->warehouses($warehouse) ?? [$warehouse]))
            ->when($c->int('supplier_id') !== null, static fn ($q) => $q->where('po.supplier_id', $c->int('supplier_id')))
            ->when($c->string('status') !== null, static fn ($q) => $q->where('po.status', $c->string('status')))
            ->selectRaw('po.id, po.supplier_id, po.po_number, po.order_date, po.status, s.name as supplier, w.name as warehouse,'
                .' COALESCE(it.ordered, 0) * COALESCE(po.exchange_rate_used, 1) as ordered, COALESCE(it.received, 0) * COALESCE(po.exchange_rate_used, 1) as received,'
                .' COALESCE(pd.paid, 0) * COALESCE(po.exchange_rate_used, 1) as paid')
            ->orderBy('po.order_date')->orderBy('po.id');
    }

    /**
     * @return array{purchase_orders: int, ordered_value: string, received_value: string, paid: string, outstanding: string}
     */
    private function purchaseTotals(ReportContext $c, string $from, string $to): array
    {
        $t = DB::connection($c->connection)->query()->fromSub($this->purchaseOrders($c, $from, $to), 'x')
            ->selectRaw('COUNT(*) as n, SUM(x.ordered) as ordered, SUM(x.received) as received, SUM(x.paid) as paid')->first();

        return [
            'purchase_orders' => (int) ($t->n ?? 0), 'ordered_value' => ReportContext::money($t->ordered ?? 0), 'received_value' => ReportContext::money($t->received ?? 0),
            'paid' => ReportContext::money($t->paid ?? 0), 'outstanding' => bcsub(ReportContext::money($t->ordered ?? 0), ReportContext::money($t->paid ?? 0), 4),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseRow(object $r): array
    {
        return [
            'id' => (int) $r->id, 'po_number' => $r->po_number, 'order_date' => substr((string) $r->order_date, 0, 10), 'supplier' => $r->supplier,
            'warehouse' => $r->warehouse, 'status' => $r->status, 'ordered_value' => ReportContext::money($r->ordered),
            'received_value' => ReportContext::money($r->received), 'paid' => ReportContext::money($r->paid),
            'outstanding' => bcsub(ReportContext::money($r->ordered), ReportContext::money($r->paid), 4),
        ];
    }

    /**
     * @return list<array{key: string, label: string, format: string}>
     */
    private static function purchaseColumns(): array
    {
        return [self::col('po_number', 'Purchase order', 'text'), self::col('order_date', 'Date', 'date'), self::col('supplier', 'Supplier', 'text'),
            self::col('warehouse', 'Warehouse', 'text'), self::col('status', 'Status', 'text'), self::col('ordered_value', 'Ordered', 'money'),
            self::col('received_value', 'Received', 'money'), self::col('paid', 'Paid', 'money'), self::col('outstanding', 'Outstanding', 'money')];
    }

    /**
     * @return list<array{key: string, label: string, format: string}>
     */
    private static function salesColumns(bool $withDate): array
    {
        return [
            ...($withDate ? [self::col('date', 'Date', 'date')] : []),
            self::col('orders', 'Orders', 'count'), self::col('gross_sales', 'Gross sales', 'money'), self::col('discounts', 'Discounts', 'money'),
            self::col('returns', 'Returns', 'money'), self::col('net_sales', 'Net sales', 'money'), self::col('tax', 'Tax', 'money'),
            self::col('shipping_revenue', 'Shipping revenue', 'money'), self::col('total_sales', 'Total sales', 'money'),
            self::col('average_order_value', 'Average order value', 'money'), self::col('units_sold', 'Units sold', 'quantity'),
            self::col('gross_profit', 'Gross profit', 'money'),
        ];
    }

    /**
     * @return array{key: string, label: string, format: string}
     */
    private static function col(string $key, string $label, string $format): array
    {
        return ['key' => $key, 'label' => $label, 'format' => $format];
    }

    /**
     * from and to as tenant days (default: the last 30 days).
     *
     * @return array{0: string, 1: string}
     */
    private function days(ReportContext $c): array
    {
        $range = $c->range();

        return [$range->from->toDateString(), $range->to->toDateString()];
    }

    private function local(ReportContext $c, mixed $utc): ?string
    {
        return $utc === null ? null : CarbonImmutable::parse((string) $utc, 'UTC')->setTimezone($c->timezone)->toIso8601String();
    }

    /**
     * Warehouses a stock report covers: the filter within the staff scope,
     * else every warehouse the user may see (null = all).
     *
     * @return list<int>|null
     */
    private function warehouses(ReportContext $c): ?array
    {
        return $c->scope->warehouses($c->int('warehouse_id'));
    }
}
