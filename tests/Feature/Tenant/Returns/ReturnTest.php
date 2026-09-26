<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Returns\Models\ReturnReason;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShipmentService;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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
    $this->guest = ['X-Guest-Token' => 'guest-token-0123456789abcdef0123456789'];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'is_active' => true]);
    $this->mug = Product::query()->create(['name' => 'Mug', 'price' => '10', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '10', 'adjustment_in');
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '10', 'adjustment_in');

    $this->reason = ReturnReason::query()->create(['label' => 'Too small', 'requires_photo' => false]);
    $this->filesBefore = returnTenantFiles();
});

afterEach(function (): void {
    foreach (array_diff(returnTenantFiles(), $this->filesBefore) as $file) {
        File::delete($file);
        @rmdir(dirname($file));
    }
});

/**
 * @return list<string>
 */
function returnTenantFiles(): array
{
    $root = base_path('storage/tenants/test-tenant-a/app');

    return is_dir($root) ? array_map(static fn ($f): string => $f->getPathname(), File::allFiles($root)) : [];
}

/**
 * A paid guest order for three shoes, shipped and delivered unless told
 * otherwise.
 */
function deliveredOrder(bool $deliver = true): Order
{
    $order = app(OrderService::class)->createOrder([
        'currency_code' => 'NGN',
        'guest_token' => 'guest-token-0123456789abcdef0123456789',
        'customer_name' => 'Ada Obi',
        'customer_email' => 'ada@shop.test',
        'lines' => [['product' => test()->shoe, 'variant' => null, 'warehouse' => test()->main, 'quantity' => '3', 'unit_price' => '40', 'price_source' => 'base', 'line_total' => '120']],
        'totals' => ['subtotal' => '120', 'total' => '120'],
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
    ]);
    app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => '120'], test()->owner);

    if ($deliver) {
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->createShipmentsForOrder($order)->first();
        $shipment->forceFill(['tracking_number' => 'T-1'])->save();
        $shipments->markDispatched($shipment);
        $shipments->markDelivered($shipment);
    }

    return $order->refresh();
}

function shoeStock(): string
{
    return (string) Inventory::query()->where('product_id', test()->shoe->id)->value('quantity');
}

it('takes a refund return from request through receipt, refund, restock and close', function (): void {
    $pending = deliveredOrder(false);
    $this->tenantJson('POST', "/api/orders/{$pending->id}/returns", ['items' => [['order_item_id' => $pending->items()->value('id'), 'quantity' => 1]], 'reason_id' => $this->reason->id, 'resolution_type' => 'refund'], $this->guest)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'order_not_returnable');

    tenancy()->initialize($this->tenant);
    $order = deliveredOrder();
    $line = $order->items()->value('id');
    $request = fn (int $quantity) => $this->tenantJson('POST', "/api/orders/{$order->id}/returns", [
        'items' => [['order_item_id' => $line, 'quantity' => $quantity]], 'reason_id' => $this->reason->id, 'resolution_type' => 'refund', 'note' => 'Half a size too small',
    ], $this->guest);

    $request(4)->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    $return = $request(2)->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonMissingPath('data.admin_note')->json('data');
    expect($return['return_number'])->toMatch('/^RET-\d{6}$/');
    $request(2)->assertStatus(422);
    Notification::assertSentOnDemand(TemplatedNotification::class, fn ($n): bool => $n->key === 'return.requested');

    $this->tenantJson('PATCH', "/api/admin/returns/{$return['id']}/approve", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'awaiting_return_shipment');
    $this->tenantJson('POST', "/api/admin/returns/{$return['id']}/refund", [], [...$this->staff, 'Idempotency-Key' => 'return-refund-1'])->assertStatus(422);

    $item = $return['items'][0]['id'];
    $this->tenantJson('PATCH', "/api/admin/returns/{$return['id']}/receive", ['items' => [['item_id' => $item, 'condition' => 'resellable']]], $this->staff)
        ->assertOk()->assertJsonPath('data.status', 'received');
    $this->tenantJson('POST', "/api/admin/returns/{$return['id']}/refund", ['amount' => '81'], [...$this->staff, 'Idempotency-Key' => 'return-refund-2'])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'refund_exceeds_return');

    // 120 × 2/3 = 80: what the customer paid for the returned units.
    $this->tenantJson('POST', "/api/admin/returns/{$return['id']}/refund", [], [...$this->staff, 'Idempotency-Key' => 'return-refund-3'])->assertOk()
        ->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.refund_amount', '80.0000')->assertJsonPath('data.items.0.restocked', true);

    tenancy()->initialize($this->tenant);
    $refund = OrderPayment::query()->where('kind', 'refund')->firstOrFail();
    expect((string) $refund->amount_paid)->toBe('-80.0000')->and($refund->order_return_id)->toBe($return['id'])
        ->and(Order::query()->find($order->id)->payment_status)->toBe('partially_refunded')
        // Two paid orders of three took six; the two resellable units came back.
        ->and(shoeStock())->toBe('6.000');
    Notification::assertSentOnDemand(TemplatedNotification::class, fn ($n): bool => $n->key === 'return.refunded');
    $this->tenantJson('PATCH', "/api/admin/returns/{$return['id']}/close", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'closed');
    $this->tenantJson('GET', "/api/returns/{$return['id']}", [], $this->guest)->assertOk()->assertJsonPath('data.status', 'closed');
    $this->tenantJson('GET', "/api/returns/{$return['id']}", [], ['X-Guest-Token' => str_repeat('c', 36)])->assertNotFound();
});

it('enforces the return window and photos, and frees quantity on rejection', function (): void {
    $order = deliveredOrder();
    $line = $order->items()->value('id');
    $photoReason = ReturnReason::query()->create(['label' => 'Damaged', 'requires_photo' => true]);
    $body = static fn (int $reason, int $quantity): array => ['items' => [['order_item_id' => $line, 'quantity' => $quantity]], 'reason_id' => $reason, 'resolution_type' => 'refund'];

    $this->tenantJson('POST', "/api/orders/{$order->id}/returns", $body($photoReason->id, 1), $this->guest)->assertStatus(422)->assertJsonValidationErrors('photos');
    $this->tenantJson('POST', "/api/orders/{$order->id}/returns", [...$body($photoReason->id, 1), 'photos' => [UploadedFile::fake()->image('crack.jpg', 200, 200)]], $this->guest)
        ->assertCreated()->assertJsonPath('data.photos_count', 1);

    $all = $this->tenantJson('POST', "/api/orders/{$order->id}/returns", $body($this->reason->id, 2), $this->guest)->assertCreated()->json('data.id');
    $this->tenantJson('POST', "/api/orders/{$order->id}/returns", $body($this->reason->id, 1), $this->guest)->assertStatus(422);
    $this->tenantJson('PATCH', "/api/admin/returns/{$all}/reject", ['reason' => 'Worn outdoors'], $this->staff)->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->tenantJson('POST', "/api/orders/{$order->id}/returns", $body($this->reason->id, 2), $this->guest)->assertCreated();

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('return_window_days', 7);
    $this->travel(8)->days();
    $this->tenantJson('POST', "/api/orders/{$order->id}/returns", $body($this->reason->id, 1), $this->guest)->assertStatus(422)->assertJsonPath('meta.error_code', 'return_window_closed');
});

it('exchanges for a cheaper item: the replacement is paid by credit and the difference refunded', function (): void {
    $order = deliveredOrder();
    $return = $this->tenantJson('POST', "/api/orders/{$order->id}/returns", [
        'items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => 1, 'exchange_for_product_id' => $this->mug->id]],
        'reason_id' => $this->reason->id, 'resolution_type' => 'exchange',
    ], $this->guest)->assertCreated()->json('data');

    $this->tenantJson('POST', "/api/admin/returns/{$return['id']}/exchange", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');
    $this->tenantJson('PATCH', "/api/admin/returns/{$return['id']}/approve", ['requires_physical_return' => false], $this->staff)->assertOk()->assertJsonPath('data.status', 'approved');

    $result = $this->tenantJson('POST', "/api/admin/returns/{$return['id']}/exchange", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'exchanged')->assertJsonPath('data.replacement_order.total', '10.0000')->assertJsonPath('data.replacement_order.payment_status', 'paid')->json('data');

    tenancy()->initialize($this->tenant);
    $replacement = Order::query()->findOrFail($result['replacement_order']['id']);
    expect($replacement->order_type)->toBe('exchange_replacement')->and($replacement->confirmed_at)->not->toBeNull()
        ->and(OrderPayment::query()->where('order_id', $replacement->id)->value('payment_method'))->toBe('exchange_credit')
        ->and((string) OrderPayment::query()->where('order_id', $order->id)->where('kind', 'refund')->value('amount_paid'))->toBe('-30.0000')
        ->and(shoeStock())->toBe('7.000')
        ->and((string) Inventory::query()->where('product_id', $this->mug->id)->value('quantity'))->toBe('9.000');
});
