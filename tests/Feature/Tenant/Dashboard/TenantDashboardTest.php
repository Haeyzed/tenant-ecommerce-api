<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'cost_price' => '25', 'is_active' => true]);
    $this->mug = Product::query()->create(['name' => 'Mug', 'price' => '10', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '20', 'adjustment_in');
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '2', 'adjustment_in');
});

/**
 * @param  list<array{0: Product, 1: string, 2: string, 3?: string}>  $lines  product, quantity, unit price, discount
 */
function dashboardOrder(array $lines, array $extra = [], bool $pay = true): Order
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
        'currency_code' => 'NGN',
        'is_test' => $extra['is_test'] ?? false,
        'customer' => $extra['customer'] ?? null,
        'guest_token' => ($extra['customer'] ?? null) === null ? 'guest-token-dashboard-0123456789abcdef' : null,
        'customer_name' => 'Ada Obi',
        'customer_email' => 'ada@shop.test',
        'lines' => $rows,
        'totals' => ['subtotal' => $subtotal, 'discount_amount' => $discount, 'shipping_amount' => $shipping, 'total' => $total],
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
    ]);

    if ($pay) {
        app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => $total], test()->owner);
    }

    return $order->refresh();
}

/**
 * @return array<string, mixed> key => KPI
 */
function kpis(array $section): array
{
    return collect($section['kpis'])->keyBy('key')->all();
}

it('computes sales figures from included orders only', function (): void {
    dashboardOrder([[$this->shoe, '2', '40', '10']], ['shipping' => '5']);
    $mugOrder = dashboardOrder([[$this->mug, '1', '10']]);
    dashboardOrder([[$this->shoe, '5', '40']], ['is_test' => true]);
    app(OrderService::class)->cancelOrder(dashboardOrder([[$this->shoe, '1', '40']], [], false), 'Changed mind');
    app(OrderPaymentService::class)->refundOrder($mugOrder, '10', 'Broken', $this->owner, 'dash-refund-1');

    $sales = kpis($this->tenantJson('GET', '/api/admin/dashboard/sales?range=today&compare=none', [], $this->staff)->assertOk()->json('data'));

    expect($sales['gross_sales']['value'])->toBe('90.0000')
        ->and($sales['discounts']['value'])->toBe('10.0000')
        ->and($sales['returns']['value'])->toBe('10.0000')
        ->and($sales['net_sales']['value'])->toBe('70.0000')
        ->and($sales['net_sales']['currency_code'])->toBe('NGN')
        ->and($sales['total_sales']['value'])->toBe('85.0000')
        ->and($sales['shipping_revenue']['value'])->toBe('5.0000')
        ->and($sales['average_order_value']['value'])->toBe('40.0000')
        ->and($sales['units_sold']['value'])->toBe(3)
        ->and($sales['gross_profit']['value'])->toBe('20.0000')
        ->and($sales['gross_profit']['supporting_label'])->toContain('1 lines without a cost');

    $overview = $this->tenantJson('GET', '/api/admin/dashboard/overview?range=today', [], $this->staff)->assertOk()->assertJsonPath('meta.cached', false)->json('data');
    $cards = kpis($overview);
    expect($cards['orders']['value'])->toBe(2)
        ->and($cards['refunds']['value'])->toBe('10.0000')
        ->and($cards['net_sales']['comparison']['value'])->toBe('0.0000')
        ->and($cards['net_sales']['comparison']['change_percent'])->toBeNull()
        ->and($cards)->toHaveKeys(['new_customers', 'low_stock'])
        ->and($cards['low_stock']['value'])->toBe(1);

    // Cached for the same viewer and parameters (§22.7).
    $this->tenantJson('GET', '/api/admin/dashboard/overview?range=today', [], $this->staff)->assertOk()->assertJsonPath('meta.cached', true);

    $orders = kpis($this->tenantJson('GET', '/api/admin/orders/metrics?range=today&compare=none', [], $this->staff)->assertOk()->json('data'));
    expect($orders['orders']['value'])->toBe(3)
        ->and($orders['cancelled']['value'])->toBe(1)
        ->and($orders['net_sales']['value'])->toBe('70.0000');
});

it('lists and serves only the sections a user may see, within their warehouses', function (): void {
    PlanLimit::query()->where('limit_key', 'max_warehouses')->update(['limit_value' => 3]);
    app(PlanLimitService::class)->flush($this->tenant);
    $annex = app(WarehouseService::class)->createWarehouse(['name' => 'Annex']);
    dashboardOrder([[$this->shoe, '2', '40']]);
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('staff_data_access_scope', 'warehouse');

    $keeper = User::query()->create(['name' => 'Keeper', 'email' => 'keeper@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $keeper->assignRole('warehouse');
    $this->tenantJson('PUT', "/api/admin/users/{$keeper->id}/warehouses", ['warehouse_ids' => [$annex->id]], $this->staff)->assertOk();
    $keeperAuth = ['Authorization' => 'Bearer '.$keeper->createToken('t', ['staff'])->plainTextToken];
    $accountant = User::query()->create(['name' => 'Books', 'email' => 'books@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $accountant->assignRole('accountant');
    $accountantAuth = ['Authorization' => 'Bearer '.$accountant->createToken('t', ['staff'])->plainTextToken];

    $all = array_column($this->tenantJson('GET', '/api/admin/dashboard', [], $this->staff)->assertOk()->json('data.sections'), 'key');
    expect($all)->toBe(['overview', 'sales', 'orders', 'customers', 'catalogue', 'inventory', 'promotions', 'payments', 'returns'])
        ->and(array_column($this->tenantJson('GET', '/api/admin/dashboard', [], $accountantAuth)->assertOk()->json('data.sections'), 'key'))->toBe(['payments', 'returns']);

    $this->tenantJson('GET', '/api/admin/dashboard/sales', [], $accountantAuth)->assertForbidden();
    $this->tenantJson('GET', '/api/admin/dashboard/nope', [], $this->staff)->assertNotFound()->assertJsonPath('meta.error_code', 'dashboard_section_not_found');
    $this->tenantJson('GET', '/api/admin/dashboard/sales?range=custom&from=2020-01-01&to=2026-01-01', [], $this->staff)->assertStatus(422);

    // The keeper's figures cover only the annex: no orders, no stock.
    $overview = kpis($this->tenantJson('GET', '/api/admin/dashboard/overview?range=today', [], $keeperAuth)->assertOk()->json('data'));
    expect($overview['orders']['value'])->toBe(0)->and($overview)->not->toHaveKey('new_customers');
    expect(kpis($this->tenantJson('GET', '/api/admin/orders/metrics?range=today', [], $keeperAuth)->assertOk()->json('data'))['orders']['value'])->toBe(0);
    expect(kpis($this->tenantJson('GET', '/api/admin/orders/metrics?range=today', [], $this->staff)->assertOk()->json('data'))['orders']['value'])->toBe(1);
    $this->tenantJson('GET', '/api/admin/customers/metrics', [], $keeperAuth)->assertForbidden();
});

it('serves every section and KPI strip', function (): void {
    dashboardOrder([[$this->shoe, '1', '40']]);

    foreach (['overview', 'sales', 'orders', 'customers', 'catalogue', 'inventory', 'promotions', 'payments', 'returns'] as $section) {
        $this->tenantJson('GET', "/api/admin/dashboard/{$section}?range=last_7_days&interval=day", [], $this->staff)
            ->assertOk()->assertJsonPath('data.section', $section)->assertJsonStructure(['data' => ['range', 'comparison_range', 'kpis', 'charts', 'tables', 'alerts']]);
    }

    foreach (ResourceMetricsController::resources() as $resource) {
        $kpis = $this->tenantJson('GET', "/api/admin/{$resource}/metrics?range=this_month", [], $this->staff)->assertOk()->json('data.kpis');
        expect($kpis)->not->toBeEmpty()->and(count($kpis))->toBeLessThanOrEqual(7);
    }

    $this->tenantJson('GET', '/api/admin/inventory/metrics?warehouse_id='.$this->main->id, [], $this->staff)->assertOk()->assertJsonPath('data.kpis.1.key', 'low_stock');
});

it('answers the tenant lookups', function (): void {
    foreach (['categories', 'brands', 'product-types', 'product-options', 'order-statuses', 'payment-statuses', 'return-statuses', 'return-reasons'] as $key) {
        $this->tenantJson('GET', "/api/lookups/{$key}")->assertOk();
    }

    $this->tenantJson('GET', '/api/lookups/order-statuses')->assertJsonPath('data.0', ['value' => 'pending', 'label' => 'Pending']);

    foreach (['customer-groups', 'warehouses', 'units-of-measure', 'shipping-zones', 'shipping-methods', 'shipping-fulfillment-types', 'delivery-assignment-statuses',
        'shipment-statuses', 'drivers', 'promotions', 'promotion-enums', 'inventory-movement-types', 'tax-rates', 'order-payment-methods', 'dashboard-sections'] as $key) {
        $this->tenantJson('GET', "/api/admin/lookups/{$key}", [], $this->staff)->assertOk();
    }

    $this->tenantJson('GET', '/api/admin/lookups/warehouses', [], $this->staff)->assertJsonPath('data.0.value', $this->main->id);
    expect(array_column($this->tenantJson('GET', '/api/admin/lookups/dashboard-sections', [], $this->staff)->json('data'), 'value'))->toContain('overview', 'returns');
});

it('anonymises an erased customer\'s orders once they are settled', function (): void {
    app(TenantSettingsService::class)->set('return_window_days', 0);
    $customer = Customer::query()->create(['name' => 'Ada Obi', 'email' => 'ada@shop.test', 'is_active' => true]);
    $ebook = Product::query()->create(['name' => 'Guide', 'price' => '5', 'product_type' => 'digital', 'is_active' => true]);
    $delivered = dashboardOrder([[$ebook, '1', '5']], ['customer' => $customer]);
    $open = dashboardOrder([[$this->shoe, '1', '40']], ['customer' => $customer], false);
    expect($delivered->status)->toBe(Order::DELIVERED);

    $this->tenantJson('DELETE', "/api/admin/customers/{$customer->id}", [], $this->staff)->assertOk();

    tenancy()->initialize($this->tenant);
    expect($delivered->refresh()->customer_name)->toBe('Deleted customer')
        ->and($delivered->customer_email)->toBeNull()
        ->and($delivered->shipping_address)->toEqualCanonicalizing(['country_id' => 1, 'state_id' => null])
        ->and($open->refresh()->customer_name)->toBe('Ada Obi');

    // The daily job finishes the order once it settles.
    app(OrderService::class)->cancelOrder($open, 'Customer left');
    expect(app(OrderService::class)->anonymizeSettledOrders())->toBe(1)
        ->and($open->refresh()->customer_email)->toBeNull()
        ->and(app(OrderService::class)->anonymizeSettledOrders())->toBe(0);
});
