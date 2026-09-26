<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Services\ShippingMethodService;
use App\Modules\Shipping\Services\ShippingZoneService;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '10', 'adjustment_in');

    $zone = app(ShippingZoneService::class)->createZone(['name' => 'Nigeria', 'regions' => [['country_id' => 1]]]);
    $this->courier = app(ShippingMethodService::class)->createMethod(['shipping_zone_id' => $zone->id, 'name' => 'GIG', 'fulfillment_type' => 'courier', 'courier_provider' => 'GIG', 'cost' => '10']);
    $this->inHouse = app(ShippingMethodService::class)->createMethod(['shipping_zone_id' => $zone->id, 'name' => 'Our riders', 'fulfillment_type' => 'in_house', 'cost' => '5']);

    $this->filesBefore = shipmentTenantFiles();
});

afterEach(function (): void {
    foreach (array_diff(shipmentTenantFiles(), $this->filesBefore) as $file) {
        File::delete($file);
        @rmdir(dirname($file));
    }
});

/**
 * @return list<string>
 */
function shipmentTenantFiles(): array
{
    $root = base_path('storage/tenants/test-tenant-a/app');

    return is_dir($root) ? array_map(static fn ($f): string => $f->getPathname(), File::allFiles($root)) : [];
}

/**
 * A confirmed order for $quantity shoes shipped by $method, paid in cash.
 */
function paidOrder(ShippingMethod $method, string $quantity = '3', bool $pay = true): Order
{
    $total = bcadd(bcmul('40', $quantity, 4), (string) $method->cost, 4);
    $order = app(OrderService::class)->createOrder([
        'currency_code' => 'NGN',
        'guest_token' => 'guest-token-0123456789abcdef0123456789',
        'customer_name' => 'Ada Obi',
        'customer_email' => 'ada@shop.test',
        'lines' => [['product' => test()->shoe, 'variant' => null, 'warehouse' => test()->main, 'quantity' => $quantity, 'unit_price' => '40', 'price_source' => 'base', 'line_total' => bcmul('40', $quantity, 4)]],
        'totals' => ['subtotal' => bcmul('40', $quantity, 4), 'shipping_amount' => (string) $method->cost, 'total' => $total],
        'shipping_method_id' => $method->id,
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
    ]);

    if ($pay) {
        app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => $total], test()->owner);
    }

    return $order->refresh();
}

it('ships a confirmed order in parts by courier and derives the order status', function (): void {
    $unpaid = paidOrder($this->courier, '1', false);
    $this->tenantJson('POST', "/api/admin/orders/{$unpaid->id}/shipments", ['all' => true], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'order_not_shippable');

    $order = paidOrder($this->courier);
    $line = $order->items()->firstOrFail()->id;

    $first = $this->tenantJson('POST', "/api/admin/orders/{$order->id}/shipments", ['warehouse_id' => $this->main->id, 'items' => [['order_item_id' => $line, 'quantity' => 2]]], $this->staff)
        ->assertCreated()->assertJsonPath('data.fulfillment_type', 'courier')->assertJsonPath('data.carrier', 'GIG')->json('data.id');
    $this->tenantJson('POST', "/api/admin/orders/{$order->id}/shipments", ['warehouse_id' => $this->main->id, 'items' => [['order_item_id' => $line, 'quantity' => 2]]], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

    $this->tenantJson('POST', "/api/admin/shipments/{$first}/dispatch", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'tracking_required');
    $this->tenantJson('PATCH', "/api/admin/shipments/{$first}", ['tracking_number' => 'GIG-1', 'tracking_url' => 'https://gig.test/GIG-1'], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/shipments/{$first}/dispatch", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'dispatched');

    $this->tenantJson('GET', "/api/admin/orders/{$order->id}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'partially_shipped')->assertJsonPath('data.items.0.quantity_shipped', '2.000');
    Notification::assertSentOnDemand(TemplatedNotification::class, fn ($n): bool => $n->key === 'order.shipped');

    // Cancelling is refused once anything has shipped.
    $this->tenantJson('POST', "/api/admin/orders/{$order->id}/cancel", ['reason' => 'x'], $this->staff)->assertStatus(422);

    $rest = $this->tenantJson('POST', "/api/admin/orders/{$order->id}/shipments", ['all' => true], $this->staff)->assertCreated()->assertJsonPath('data.0.items.0.quantity', '1.000')->json('data.0.id');
    $this->tenantJson('PATCH', "/api/admin/shipments/{$rest}", ['tracking_number' => 'GIG-2'], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/shipments/{$rest}/dispatch", [], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/orders/{$order->id}/shipments", ['all' => true], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'nothing_to_ship');

    tenancy()->initialize($this->tenant);
    expect(Order::query()->find($order->id)->status)->toBe('shipped');

    $this->tenantJson('POST', "/api/admin/shipments/{$first}/delivered", [], $this->staff)->assertOk();
    tenancy()->initialize($this->tenant);
    expect(Order::query()->find($order->id)->status)->toBe('shipped');

    $this->tenantJson('POST', "/api/admin/shipments/{$rest}/in-transit", [], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/shipments/{$rest}/delivered", [], $this->staff)->assertOk();

    tenancy()->initialize($this->tenant);
    $model = Order::query()->find($order->id);
    expect($model->status)->toBe('delivered')->and($model->completed_at)->not->toBeNull();
    $this->tenantJson('POST', "/api/admin/shipments/{$rest}/cancel", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');

    // The guest follows every parcel with its token.
    $this->tenantJson('GET', "/api/orders/{$order->id}/tracking", [], ['X-Guest-Token' => 'guest-token-0123456789abcdef0123456789'])->assertOk()
        ->assertJsonCount(2, 'data.shipments')->assertJsonPath('data.shipments.0.tracking_number', 'GIG-1')->assertJsonMissingPath('data.shipments.0.warehouse');
    $this->tenantJson('GET', "/api/orders/{$order->id}/tracking", [], ['X-Guest-Token' => Str::uuid()->toString()])->assertNotFound();
});

it('delivers in house through the driver app, with a failure and a reassignment', function (): void {
    $order = paidOrder($this->inHouse, '1');
    $shipment = $this->tenantJson('POST', "/api/admin/orders/{$order->id}/shipments", ['all' => true], $this->staff)->assertCreated()
        ->assertJsonPath('data.0.fulfillment_type', 'in_house')->json('data.0.id');

    tenancy()->initialize($this->tenant);
    $tunde = Driver::query()->create(['name' => 'Tunde', 'phone' => '+2348000000001']);
    $tunde->forceFill(['status' => 'active'])->save();
    $bola = Driver::query()->create(['name' => 'Bola', 'phone' => '+2348000000002']);
    $bola->forceFill(['status' => 'active'])->save();
    $tundeAuth = ['Authorization' => 'Bearer '.$tunde->createToken('t', ['driver'])->plainTextToken];
    $bolaAuth = ['Authorization' => 'Bearer '.$bola->createToken('t', ['driver'])->plainTextToken];

    $assignment = $this->tenantJson('POST', "/api/admin/shipments/{$shipment}/assign-driver", ['driver_id' => $tunde->id], $this->staff)->assertCreated()->json('data.id');
    $this->tenantJson('POST', "/api/admin/shipments/{$shipment}/assign-driver", ['driver_id' => $bola->id], $this->staff)->assertStatus(409);

    $this->tenantJson('GET', '/api/driver/deliveries', [], $tundeAuth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shipping_address.line1', '1 Marina');
    $this->tenantJson('GET', '/api/driver/deliveries', [], $bolaAuth)->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('POST', "/api/driver/deliveries/{$assignment}/pick-up", [], $bolaAuth)->assertNotFound();

    $this->tenantJson('POST', "/api/driver/deliveries/{$assignment}/pick-up", [], $tundeAuth)->assertOk()->assertJsonPath('data.status', 'picked_up');
    $this->tenantJson('POST', "/api/driver/deliveries/{$assignment}/en-route", [], $tundeAuth)->assertOk();
    Notification::assertSentOnDemand(TemplatedNotification::class, fn ($n): bool => $n->key === 'order.out_for_delivery');
    $this->tenantJson('POST', "/api/driver/deliveries/{$assignment}/failed", ['reason' => 'Nobody home'], $tundeAuth)->assertOk()->assertJsonPath('data.status', 'failed');

    $this->tenantJson('GET', "/api/admin/orders/{$order->id}", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'processing')->assertJsonPath('data.items.0.quantity_shipped', '0.000');

    $this->tenantJson('PATCH', "/api/admin/delivery-assignments/{$assignment}/reassign", ['driver_id' => $bola->id], $this->staff)->assertOk()->assertJsonPath('data.status', 'assigned');
    $this->tenantJson('GET', "/api/admin/orders/{$order->id}/shipments", [], $this->staff)->assertOk()->assertJsonPath('data.0.status', 'pending');

    $this->tenantJson('POST', "/api/driver/deliveries/{$assignment}/pick-up", [], $bolaAuth)->assertOk();
    $this->tenantJson('POST', "/api/driver/deliveries/{$assignment}/delivered", ['proof' => UploadedFile::fake()->image('door.jpg', 300, 300)], $bolaAuth)->assertOk()->assertJsonPath('data.status', 'delivered');

    tenancy()->initialize($this->tenant);
    expect(Order::query()->find($order->id)->status)->toBe('delivered');

    $this->tenantJson('GET', "/api/orders/{$order->id}/tracking", [], ['X-Guest-Token' => 'guest-token-0123456789abcdef0123456789'])->assertOk()
        ->assertJsonPath('data.shipments.0.delivery.status', 'delivered')->assertJsonPath('data.shipments.0.delivery.driver', ['name' => 'Bola']);
});

it('gives back shipped quantity when a dispatched shipment is cancelled', function (): void {
    $order = paidOrder($this->courier, '2');
    $shipment = $this->tenantJson('POST', "/api/admin/orders/{$order->id}/shipments", ['all' => true, 'tracking_number' => 'x'], $this->staff)->json('data.0.id');
    $this->tenantJson('PATCH', "/api/admin/shipments/{$shipment}", ['tracking_number' => 'GIG-9'], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/shipments/{$shipment}/dispatch", [], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/shipments/{$shipment}/cancel", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->tenantJson('GET', "/api/admin/orders/{$order->id}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'processing')->assertJsonPath('data.items.0.quantity_shipped', '0.000');

    // The quantity can ship again.
    $this->tenantJson('POST', "/api/admin/orders/{$order->id}/shipments", ['all' => true], $this->staff)->assertCreated();
    $this->tenantJson('GET', '/api/admin/shipments?status=cancelled', [], $this->staff)->assertOk()->assertJsonCount(1, 'data');
});
