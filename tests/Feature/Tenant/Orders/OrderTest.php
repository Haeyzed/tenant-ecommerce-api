<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Jobs\ExpireUnpaidOrder;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Jobs\VerifyOrderPayment;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\Promotions\Models\FlashSaleProduct;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Services\CouponService;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShippingMethodService;
use App\Modules\Shipping\Services\ShippingZoneService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'cost_price' => '25', 'is_active' => true]);
    $this->ebook = Product::query()->create(['name' => 'Guide', 'price' => '5', 'product_type' => 'digital', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '5', 'adjustment_in');

    app(TaxService::class)->createTaxRate(['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => '7.5']);
    $zone = app(ShippingZoneService::class)->createZone(['name' => 'Nigeria', 'regions' => [['country_id' => 1]]]);
    $this->method = app(ShippingMethodService::class)->createMethod(['shipping_zone_id' => $zone->id, 'name' => 'GIG', 'fulfillment_type' => 'courier', 'cost' => '10']);
});

/**
 * A guest cart with the given lines, quoted for Lagos with shipping.
 *
 * @param  array<int, int>  $lines  product id => quantity
 * @return array{token: string, quote: array<string, mixed>, body: array<string, mixed>}
 */
function guestBasket(array $lines, array $extra = []): array
{
    $token = null;

    foreach ($lines as $productId => $quantity) {
        $token = test()->tenantJson('POST', '/api/cart/items', ['product_id' => $productId, 'quantity' => $quantity], $token === null ? [] : ['X-Guest-Token' => $token])
            ->assertCreated()->json('data.guest_token');
    }

    if (($extra['coupon'] ?? null) !== null) {
        test()->tenantJson('POST', '/api/cart/apply-coupon', ['code' => $extra['coupon']], ['X-Guest-Token' => $token])->assertOk();
    }

    $quote = test()->tenantJson('GET', '/api/cart?country_id=1&shipping_method_id='.test()->method->id, [], ['X-Guest-Token' => $token])->json('data.quote');

    return ['token' => $token, 'quote' => $quote, 'body' => [
        'quote_hash' => $quote['quote_hash'],
        'guest_email' => 'Ada@Shop.test',
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
        'shipping_method_id' => test()->method->id,
    ]];
}

function placeOrder(array $basket, ?string $key = null): TestResponse
{
    return test()->tenantJson('POST', '/api/orders', $basket['body'], ['X-Guest-Token' => $basket['token'], 'Idempotency-Key' => $key ?? Str::uuid()->toString()]);
}

function stock(Product $product): array
{
    $row = Inventory::query()->where('product_id', $product->id)->firstOrFail();

    return [(string) $row->quantity, (string) $row->reserved_quantity];
}

it('places a guest order once per key, reserves stock and keeps it private to the guest', function (): void {
    $basket = guestBasket([$this->shoe->id => 2, $this->ebook->id => 1]);
    $key = Str::uuid()->toString();

    $order = placeOrder($basket, $key)->assertCreated()->json('data');
    expect($order['order_number'])->toBe('100001')
        ->and($order['status'])->toBe('pending')
        ->and($order['payment_status'])->toBe('unpaid')
        ->and($order['total'])->toBe($basket['quote']['total'])
        ->and($order['balance_due'])->toBe($basket['quote']['total'])
        ->and($order['customer_email'])->toBe('ada@shop.test')
        ->and($order['payment_expires_at'])->not->toBeNull()
        ->and($order['items'])->toHaveCount(2)
        ->and($order['items'][0])->not->toHaveKey('unit_cost_snapshot');

    // A retry with the same key replays the response; no second order.
    placeOrder($basket, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $order['id']);

    tenancy()->initialize($this->tenant);
    expect(Order::query()->count())->toBe(1)->and(stock($this->shoe))->toBe(['5.000', '2.000']);

    $this->tenantJson('GET', '/api/cart', [], ['X-Guest-Token' => $basket['token']])->assertOk()->assertJsonPath('data.items_count', 0);
    $this->tenantJson('GET', "/api/orders/{$order['id']}", [], ['X-Guest-Token' => $basket['token']])->assertOk()->assertJsonPath('data.order_number', '100001');
    $this->tenantJson('GET', "/api/orders/{$order['id']}", [], ['X-Guest-Token' => Str::uuid()->toString()])->assertNotFound();
    $this->tenantJson('GET', "/api/orders/{$order['id']}")->assertNotFound();

    $this->tenantJson('GET', "/api/admin/orders/{$order['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.items.0.unit_cost_snapshot', '25.0000')->assertJsonPath('data.items.0.warehouse.id', $this->main->id);
});

it('guards the price, the buyer and the stock at checkout', function (): void {
    $basket = guestBasket([$this->shoe->id => 1]);

    placeOrder(['token' => $basket['token'], 'body' => [...$basket['body'], 'quote_hash' => str_repeat('0', 64)]])
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'totals_changed')->assertJsonPath('meta.details.quote.quote_hash', $basket['quote']['quote_hash']);
    placeOrder(['token' => $basket['token'], 'body' => array_diff_key($basket['body'], ['guest_email' => true])])->assertStatus(422)->assertJsonValidationErrors('guest_email');
    placeOrder(['token' => $basket['token'], 'body' => array_diff_key($basket['body'], ['shipping_address' => true])])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'shipping_address_required');
    $this->tenantJson('POST', '/api/orders', $basket['body'], ['X-Guest-Token' => $basket['token']])->assertStatus(422); // no Idempotency-Key

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('guest_checkout_enabled', false);
    placeOrder($basket)->assertForbidden()->assertJsonPath('meta.error_code', 'guest_checkout_disabled');
    app(TenantSettingsService::class)->set('guest_checkout_enabled', true);

    // Someone else took the stock after the quote.
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '-5', 'adjustment_out');
    placeOrder($basket)->assertStatus(409)->assertJsonPath('meta.error_code', 'stock_conflict');
});

it('reserves promotions and flash-sale quantity, and releases everything when the order is cancelled', function (): void {
    // Sale-priced lines are eligible only when the promotion says so (§37.6 step 3).
    $promotion = app(PromotionService::class)->createPromotion(['name' => '10% off', 'trigger' => 'coupon', 'scope' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10, 'applies_to_sale_items' => true]);
    app(CouponService::class)->createCoupon($promotion, ['code' => 'SAVE10']);
    $sale = app(FlashSaleService::class)->createFlashSale(['name' => 'Now', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    app(FlashSaleService::class)->addProduct($sale, $this->shoe, '30', '3');

    $basket = guestBasket([$this->shoe->id => 2], ['coupon' => 'SAVE10']);
    expect($basket['quote']['lines'][0]['price_source'])->toBe('flash_sale')->and($basket['quote']['discount_amount'])->toBe('6.0000');

    $order = placeOrder($basket)->assertCreated()->assertJsonPath('data.promotions.0.coupon_code', 'SAVE10')->json('data');

    tenancy()->initialize($this->tenant);
    expect(Promotion::query()->find($promotion->id)->times_redeemed)->toBe(1)
        ->and((string) FlashSaleProduct::query()->firstOrFail()->quantity_claimed)->toBe('2.000');

    $this->tenantJson('POST', "/api/orders/{$order['id']}/cancel", [], ['X-Guest-Token' => $basket['token']])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Cancelled by the customer');

    tenancy()->initialize($this->tenant);
    expect(Promotion::query()->find($promotion->id)->times_redeemed)->toBe(0)
        ->and((string) FlashSaleProduct::query()->firstOrFail()->quantity_claimed)->toBe('0.000')
        ->and(DB::connection('tenant')->table('promotion_redemptions')->value('status'))->toBe('released')
        ->and(stock($this->shoe))->toBe(['5.000', '0.000']);
});

it('confirms on full payment, restocks a cancelled paid order and refunds by hand', function (): void {
    $basket = guestBasket([$this->shoe->id => 2]);
    $order = placeOrder($basket)->assertCreated()->json('data');
    $total = $order['total'];

    $this->tenantJson('POST', "/api/admin/orders/{$order['id']}/payments", ['payment_method' => 'cash', 'amount' => '50', 'amount_received' => '60'], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertCreated()->assertJsonPath('data.change_given', '10.0000');
    $this->tenantJson('GET', "/api/admin/orders/{$order['id']}/balance", [], $this->staff)->assertOk()->assertJsonPath('data.payment_status', 'partially_paid');

    $rest = bcsub($total, '50', 4);
    $this->tenantJson('POST', "/api/admin/orders/{$order['id']}/payments", ['payment_method' => 'bank_transfer', 'amount' => $rest], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])->assertCreated();

    tenancy()->initialize($this->tenant);
    $model = Order::query()->findOrFail($order['id']);
    expect($model->payment_status)->toBe('paid')->and($model->confirmed_at)->not->toBeNull()->and($model->payment_expires_at)->toBeNull()
        ->and(stock($this->shoe))->toBe(['3.000', '0.000']);

    // A customer cannot cancel a paid order; staff can, and stock returns.
    $this->tenantJson('POST', "/api/orders/{$order['id']}/cancel", [], ['X-Guest-Token' => $basket['token']])->assertStatus(422)->assertJsonPath('meta.error_code', 'order_paid');
    $this->tenantJson('POST', "/api/admin/orders/{$order['id']}/cancel", ['reason' => 'Out of season'], $this->staff)->assertOk()->assertJsonPath('data.status', 'cancelled');

    tenancy()->initialize($this->tenant);
    expect(stock($this->shoe))->toBe(['5.000', '0.000']);

    $this->tenantJson('POST', "/api/admin/orders/{$order['id']}/refund", ['amount' => bcadd($total, '1', 4), 'reason' => 'Too much'], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'refund_exceeds_payment');
    $refund = $this->tenantJson('POST', "/api/admin/orders/{$order['id']}/refund", ['reason' => 'Cancelled order'], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertOk()->assertJsonCount(2, 'data.refunds')->json('data');
    expect($refund['order']['payment_status'])->toBe('refunded')->and($refund['order']['status'])->toBe('cancelled');

    // Manual rows only: a refunded payment cannot be deleted.
    $this->tenantJson('DELETE', "/api/admin/order-payments/{$refund['refunds'][0]['id']}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'payment_not_manual');
});

it('expires an unpaid order and releases its stock', function (): void {
    $order = placeOrder(guestBasket([$this->shoe->id => 1]))->assertCreated()->json('data');

    tenancy()->initialize($this->tenant);
    (new ExpireUnpaidOrder($this->tenant->id, $order['id']))->handle();
    expect(Order::query()->find($order['id'])->status)->toBe('pending');

    $this->travel(61)->minutes();
    (new ExpireUnpaidOrder($this->tenant->id, $order['id']))->handle();

    $model = Order::query()->findOrFail($order['id']);
    expect($model->status)->toBe('cancelled')->and($model->cancellation_reason)->toBe('payment_timeout')->and(stock($this->shoe))->toBe(['5.000', '0.000']);
});

it('takes a gateway payment through the webhook, then refunds it and records a lost chargeback', function (): void {
    // The delayed verification runs at once on the sync queue; it is covered on its own.
    Bus::fake([VerifyOrderPayment::class]);
    tenancy()->initialize($this->tenant);
    TenantPaymentSetting::query()->create(['provider' => 'paystack', 'mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => self::PAYSTACK_TEST_SECRET, 'is_active' => true, 'enabled_for_online' => true]);

    $basket = guestBasket([$this->ebook->id => 2]);
    $order = placeOrder($basket)->assertCreated()->assertJsonPath('data.payment_methods', ['paystack'])->json('data');
    $guest = ['X-Guest-Token' => $basket['token']];

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/x', 'access_code' => 'x', 'reference' => 'r']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'ongoing', 'amount' => 0, 'currency' => 'NGN']]),
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 777, 'status' => 'processed']]),
    ]);

    $pay = $this->tenantJson('POST', "/api/orders/{$order['id']}/pay", ['gateway' => 'paystack'], [...$guest, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.paystack.test/x')->json('data');
    $this->tenantJson('POST', "/api/orders/{$order['id']}/pay", ['gateway' => 'paystack'], [...$guest, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'payment_in_progress');

    // The webhook for another amount is flagged, never confirmed…
    $minor = (int) bcmul($order['total'], '100', 0);
    $raw = (string) json_encode($this->paystackChargeSuccess($pay['reference'], $minor + 100, 'NGN'));
    $webhook = fn (string $body) => $this->call('POST', 'http://'.$this->landlordHost()."/api/webhooks/{$this->tenant->id}/paystack/test", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::PAYSTACK_TEST_SECRET),
    ], $body);

    $webhook(str_replace('"amount":'.($minor + 100), '"amount":'.$minor, $raw))->assertOk();

    tenancy()->initialize($this->tenant);
    $model = Order::query()->findOrFail($order['id']);
    expect($model->payment_status)->toBe('paid')->and($model->status)->toBe('delivered')->and($model->confirmed_at)->not->toBeNull();

    // A bad signature is refused.
    $this->call('POST', 'http://'.$this->landlordHost()."/api/webhooks/{$this->tenant->id}/paystack/test", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'bad'], $raw)->assertUnauthorized();

    $this->tenantJson('POST', "/api/admin/orders/{$order['id']}/refund", ['amount' => '2', 'reason' => 'Goodwill'], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertOk()->assertJsonPath('data.refunds.0.status', 'successful')->assertJsonPath('data.order.payment_status', 'partially_refunded');

    // A dispute opened and lost on the rest.
    $payment = OrderPayment::query()->where('reference', $pay['reference'])->firstOrFail();
    $dispute = static fn (string $event, array $extra) => (string) json_encode(['event' => $event, 'data' => array_merge([
        'id' => 9001, 'currency' => 'NGN', 'refund_amount' => 300, 'domain' => 'test', 'transaction' => ['reference' => $payment->reference, 'amount' => $minor, 'currency' => 'NGN'],
    ], $extra)]);
    $webhook($dispute('charge.dispute.create', []))->assertOk();
    $webhook($dispute('charge.dispute.resolve', ['resolution' => 'merchant-accepted']))->assertOk();

    tenancy()->initialize($this->tenant);
    $chargeback = OrderPayment::query()->where('kind', 'chargeback')->firstOrFail();
    expect($chargeback->status)->toBe('successful')->and((string) $chargeback->amount_paid)->toBe('-3.0000')
        ->and(Order::query()->find($order['id'])->payment_status)->toBe('partially_refunded');
});
