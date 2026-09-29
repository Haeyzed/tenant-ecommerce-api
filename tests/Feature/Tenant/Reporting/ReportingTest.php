<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    // advanced_reporting comes with Standard and above, switched on automatically.
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $clerk->assignRole('staff');
    $this->clerkAuth = ['Authorization' => 'Bearer '.$clerk->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'sku' => 'RUN-1', 'price' => '40', 'cost_price' => '25', 'is_active' => true]);
    $this->mug = Product::query()->create(['name' => 'Mug', 'sku' => 'MUG-1', 'price' => '10', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '20', 'adjustment_in');
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '2', 'adjustment_in');
    $footwear = Category::query()->create(['name' => 'Footwear', 'is_active' => true]);
    $this->shoe->categories()->attach($footwear->id, ['is_primary' => true]);
    $this->footwear = $footwear;

    // The dashboard's fixture (TenantDashboardTest): net sales 70, gross profit 20.
    reportOrder([[$this->shoe, '2', '40', '10']], ['shipping' => '5']);
    $mugOrder = reportOrder([[$this->mug, '1', '10']]);
    reportOrder([[$this->shoe, '5', '40']], ['is_test' => true]);
    app(OrderService::class)->cancelOrder(reportOrder([[$this->shoe, '1', '40']], [], false), 'Changed mind');
    app(OrderPaymentService::class)->refundOrder($mugOrder, '10', 'Broken', $this->owner, 'report-refund-1');
});

/**
 * @param  list<array{0: Product, 1: string, 2: string, 3?: string}>  $lines  product, quantity, unit price, discount
 */
function reportOrder(array $lines, array $extra = [], bool $pay = true): Order
{
    $subtotal = '0';
    $discount = '0';
    $rows = [];

    foreach ($lines as $line) {
        $gross = bcmul($line[1], $line[2], 4);
        $lineDiscount = $line[3] ?? '0';
        $subtotal = bcadd($subtotal, $gross, 4);
        $discount = bcadd($discount, $lineDiscount, 4);
        $rows[] = ['product' => $line[0], 'variant' => null, 'warehouse' => test()->main, 'quantity' => $line[1], 'unit_price' => $line[2],
            'price_source' => 'base', 'discount_amount' => $lineDiscount, 'line_total' => bcsub($gross, $lineDiscount, 4)];
    }

    $shipping = $extra['shipping'] ?? '0';
    $total = bcadd(bcsub($subtotal, $discount, 4), $shipping, 4);
    $order = app(OrderService::class)->createOrder([
        'currency_code' => 'NGN', 'is_test' => $extra['is_test'] ?? false, 'guest_token' => 'guest-token-report-0123456789abcdef',
        'customer_name' => 'Ada Obi', 'customer_email' => 'ada@shop.test', 'lines' => $rows,
        'totals' => ['subtotal' => $subtotal, 'discount_amount' => $discount, 'shipping_amount' => $shipping, 'total' => $total],
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
    ]);

    if ($pay) {
        app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => $total], test()->owner);
    }

    return $order->refresh();
}

it('reports the same sales and profit as the dashboard, with filters, pagination and breakdowns', function (): void {
    $dashboard = collect($this->tenantJson('GET', '/api/admin/dashboard/sales?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key');

    $daily = $this->tenantJson('GET', '/api/admin/reports/sales/daily', [], $this->staff)->assertOk()->assertJsonPath('data.currency', 'NGN')->json('data.summary');
    expect($daily)->toMatchArray(['gross_sales' => '90.0000', 'discounts' => '10.0000', 'returns' => '10.0000', 'net_sales' => '70.0000', 'orders' => 2, 'gross_profit' => '20.0000'])
        ->and($daily['net_sales'])->toBe($dashboard['net_sales'])->and($daily['gross_profit'])->toBe($dashboard['gross_profit'])
        ->and($daily['average_order_value'])->toBe($dashboard['average_order_value']);

    $this->tenantJson('GET', '/api/admin/reports/profit-loss', [], $this->staff)->assertOk()
        ->assertJsonPath('data.summary.net_sales', '70.0000')->assertJsonPath('data.summary.cost_of_goods_sold', '50.0000')
        ->assertJsonPath('data.summary.gross_profit', '20.0000')->assertJsonPath('data.summary.gross_margin_percent', '28.57')
        ->assertJsonPath('data.summary.cost_unknown_lines', 1);

    // The sales register: two included orders, a page at a time.
    $this->tenantJson('GET', '/api/admin/reports/sales?per_page=1', [], $this->staff)->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.gross_sales', '80.0000')
        ->assertJsonPath('data.rows.0.shipping', '5.0000');
    // A product filter keeps the matching lines; returns are order-level.
    $this->tenantJson('GET', "/api/admin/reports/sales/daily?category_id={$this->footwear->id}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.summary.gross_sales', '80.0000')->assertJsonPath('data.summary.orders', 1)->assertJsonPath('data.summary.returns', '0.0000')
        ->assertJsonCount(1, 'data.notes');

    $products = collect($this->tenantJson('GET', '/api/admin/reports/profit-by-product', [], $this->staff)->assertOk()->json('data.rows'))->keyBy('label');
    expect($products['Runner'])->toMatchArray(['revenue' => '70.0000', 'cost' => '50.0000', 'profit' => '20.0000', 'margin_percent' => '28.57', 'cost_unknown_lines' => 0])
        ->and($products['Mug'])->toMatchArray(['revenue' => '10.0000', 'profit' => '0.0000', 'margin_percent' => null, 'cost_unknown_lines' => 1]);
    $this->tenantJson('GET', '/api/admin/reports/profit-by-category', [], $this->staff)->assertOk()->assertJsonPath('data.rows.0.label', 'Footwear');
    $this->tenantJson('GET', '/api/admin/reports/profit-by-location', [], $this->staff)->assertOk()->assertJsonPath('data.rows.0.label', $this->main->name);
    $this->tenantJson('GET', '/api/admin/reports/profit-by-customer', [], $this->staff)->assertOk()->assertJsonPath('data.rows.0.label', 'Guests');
    $this->tenantJson('GET', '/api/admin/reports/profit-by-invoice', [], $this->staff)->assertOk()->assertJsonCount(2, 'data.rows');
    $this->tenantJson('GET', '/api/admin/reports/bestsellers', [], $this->staff)->assertOk()
        ->assertJsonPath('data.rows.0.name', 'Runner')->assertJsonPath('data.rows.0.units', '2.000');

    $chart = $this->tenantJson('GET', '/api/admin/reports/sales/chart?group_by=day&from='.today()->subDays(2)->toDateString(), [], $this->staff)->assertOk()->json('data');
    expect($chart['chart']['series'][0]['points'])->toHaveCount(3)->and(end($chart['rows'])['net_sales'])->toBe('70.0000');
    $this->tenantJson('GET', '/api/admin/reports/sales/monthly', [], $this->staff)->assertOk()->assertJsonPath('data.summary.net_sales', '70.0000');

    $this->tenantJson('GET', '/api/admin/reports/payments', [], $this->staff)->assertOk()
        ->assertJsonPath('data.rows.0.payment_method', 'cash')->assertJsonPath('data.rows.0.received', '85.0000')->assertJsonPath('data.rows.0.net', '75.0000');
    $this->tenantJson('GET', '/api/admin/reports/users', [], $this->staff)->assertOk()->assertJsonPath('data.rows.0.name', 'Owner');
    $this->tenantJson('GET', '/api/admin/reports/sales?from=2026-02-01&to=2026-01-01', [], $this->staff)->assertStatus(422);
});

it('reports stock, movements and alerts, and keeps reports behind their permission and features', function (): void {
    $stock = collect($this->tenantJson('GET', '/api/admin/reports/stock', [], $this->staff)->assertOk()->json('data.rows'))->keyBy('sku');
    // 20 received, 2 sold and 5 by a test order (test orders move stock too); the cancelled order's reservation went back.
    expect($stock['RUN-1'])->toMatchArray(['on_hand' => '13.000', 'available' => '13.000', 'stock_value' => '325.0000']);
    $this->tenantJson('GET', '/api/admin/reports/product-quantity-alerts', [], $this->staff)->assertOk()
        ->assertJsonPath('data.rows.0.sku', 'MUG-1')->assertJsonPath('data.rows.0.available', '1.000');
    $this->tenantJson('GET', '/api/admin/reports/warehouse-stock', [], $this->staff)->assertOk()->assertJsonPath('data.rows.0.on_hand', '14.000');
    $this->tenantJson('GET', "/api/admin/reports/warehouse-stock/chart?warehouse_id={$this->main->id}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.chart.series.0.points.0.x', 'RUN-1');
    $moves = collect($this->tenantJson('GET', '/api/admin/reports/stock-movements', [], $this->staff)->assertOk()->json('data.rows'))->keyBy('key');
    expect($moves['adjustment_in'])->toMatchArray(['quantity_in' => '22.000', 'movements' => 2]);
    $this->tenantJson('GET', "/api/admin/reports/products?category_id={$this->footwear->id}", [], $this->staff)->assertOk()
        ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.units_sold', '2.000')->assertJsonPath('data.rows.0.available', '13.000');

    // Permission per report; optional modules add their feature.
    $this->tenantJson('GET', '/api/admin/reports/profit-loss', [], $this->clerkAuth)->assertForbidden();
    $this->tenantJson('GET', '/api/admin/reports/purchases', [], $this->staff)->assertForbidden()->assertJsonPath('meta.error_code', 'module_disabled');
    $this->tenantJson('POST', '/api/admin/reports/purchases/export', ['format' => 'csv'], $this->staff)->assertForbidden();
    $this->tenantJson('POST', '/api/admin/modules/advanced_reporting/disable', [], $this->staff)->assertOk();
    $this->tenantJson('GET', '/api/admin/reports/stock', [], $this->staff)->assertForbidden();
});

it('exports a report as CSV and PDF through the export mechanism', function (): void {

    $export = $this->tenantJson('POST', '/api/admin/reports/profit-by-product/export', ['format' => 'csv', 'filters' => ['from' => today()->toDateString()]], $this->staff)
        ->assertStatus(202)->assertJsonPath('data.export_type', 'report:profit-by-product')->json('data');
    tenancy()->initialize($this->tenant);
    $row = DataExport::query()->findOrFail($export['id']);
    expect($row->status)->toBe(DataExport::COMPLETED)->and($row->row_count)->toBe(2);
    $csv = (string) file_get_contents($row->getFirstMedia('file')->getPath());
    expect(strtok($csv, "\n"))->toBe('Product,Code,Units,Revenue,Cost,"Gross profit","Margin %","Lines without a cost"')
        ->and($csv)->toContain('Runner,RUN-1,2.000,70.0000,50.0000,20.0000,28.57,0');

    $pdf = $this->tenantJson('POST', '/api/admin/reports/sales/export', ['format' => 'pdf'], $this->staff)->assertStatus(202)->json('data');
    tenancy()->initialize($this->tenant);
    $row = DataExport::query()->findOrFail($pdf['id']);
    expect($row->status)->toBe(DataExport::COMPLETED)->and($row->row_count)->toBe(2)
        ->and(substr((string) file_get_contents($row->getFirstMedia('file')->getPath()), 0, 4))->toBe('%PDF');

    // The report's own permission is required, whichever route asks.
    $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'report:profit-loss', 'format' => 'csv', 'parameters' => []], $this->clerkAuth)->assertForbidden();
    // Reports export as CSV, XLSX or PDF (D-134); JSON is not offered.
    $this->tenantJson('POST', '/api/admin/reports/profit-loss/export', ['format' => 'json'], $this->staff)->assertStatus(422);
    expect(DB::connection('tenant')->table('data_exports')->count())->toBe(2);
});
