<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Jobs\VerifyOrderPayment;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\ProductSubscriptions\Models\CustomerSubscription;
use App\Modules\ProductSubscriptions\Services\ProductSubscriptionService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShippingMethodService;
use App\Modules\Shipping\Services\ShippingZoneService;
use App\Modules\Users\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    Bus::fake([VerifyOrderPayment::class]);
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    // Rehearsed with the provider's test credentials: test orders, charged in test mode.
    app(TenantSettingsService::class)->set('payment_mode', 'test');
    TenantPaymentSetting::query()->create(['provider' => 'paystack', 'mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => self::PAYSTACK_TEST_SECRET, 'is_active' => true, 'enabled_for_online' => true]);
    User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true])->assignRole('owner');

    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    $this->bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@shop.test', 'password' => 'Secret123']);
    $address = new Address;
    $address->forceFill(['customer_id' => $this->ada->id, 'recipient_name' => 'Ada Obi', 'address_line_1' => '1 Marina', 'country_id' => 1, 'is_default' => true])->save();
    $this->address = $address;
    subscriptionTokens();

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->coffee = Product::query()->create(['name' => 'Coffee beans', 'slug' => 'coffee-beans', 'price' => '10000', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->coffee, null, '20', 'adjustment_in');
    $zone = app(ShippingZoneService::class)->createZone(['name' => 'Nigeria', 'regions' => [['country_id' => 1]]]);
    $this->method = app(ShippingMethodService::class)->createMethod(['shipping_zone_id' => $zone->id, 'name' => 'GIG', 'fulfillment_type' => 'courier', 'cost' => '1000']);

    // The provider: charge_authorization succeeds unless the test says otherwise.
    $this->chargeSucceeds = true;
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/x', 'access_code' => 'x', 'reference' => 'r']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'ongoing', 'amount' => 0, 'currency' => 'NGN']]),
        'api.paystack.co/transaction/charge_authorization' => fn () => test()->chargeSucceeds
            ? Http::response(['status' => true, 'data' => ['status' => 'success', 'id' => random_int(1000, 9999)]])
            : Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient Funds']]),
    ]);

    $this->tenantJson('POST', '/api/admin/modules/product_subscriptions/enable', [], $this->staff)->assertOk();

    // Ada pays an order; the provider's webhook returns the reusable authorization.
    $this->payFirstOrder = function (array $order, array $overrides = []): void {
        $pay = $this->tenantJson('POST', "/api/orders/{$order['id']}/pay", ['gateway' => 'paystack'], [...$this->adaAuth, 'Idempotency-Key' => Str::uuid()->toString()])
            ->assertOk()->json('data');
        $body = (string) json_encode($this->paystackChargeSuccess($pay['reference'], (int) bcmul($order['total'], '100', 0), 'NGN', $overrides));
        $this->call('POST', 'http://'.$this->landlordHost()."/api/webhooks/{$this->tenant->id}/paystack/test", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::PAYSTACK_TEST_SECRET),
        ], $body)->assertOk();
    };

    // Ada subscribes to the coffee and pays the first order.
    $this->adaSubscribes = function (array $plan, string $quantity = '2'): array {
        $created = $this->tenantJson('POST', '/api/account/product-subscriptions', [
            'product_id' => $this->coffee->id, 'product_subscription_plan_id' => $plan['id'], 'quantity' => $quantity,
            'address_id' => $this->address->id, 'shipping_method_id' => $this->method->id,
        ], $this->adaAuth)->assertCreated()->json('data');
        ($this->payFirstOrder)($created['order']);

        return $created;
    };
});

/**
 * Staff, Ada and Bola tokens; minted again after travelling past their expiry.
 */
function subscriptionTokens(): void
{
    tenancy()->initialize(test()->tenant);
    test()->staff = ['Authorization' => 'Bearer '.User::query()->where('email', 'owner@a.test')->firstOrFail()->createToken('t', ['staff'])->plainTextToken];
    test()->adaAuth = ['Authorization' => 'Bearer '.test()->ada->createToken('t', ['customer'])->plainTextToken];
    test()->bolaAuth = ['Authorization' => 'Bearer '.test()->bola->createToken('t', ['customer'])->plainTextToken];
}

/**
 * The coffee offered monthly at 10% off; returns the plan.
 *
 * @return array<string, mixed>
 */
function monthlyCoffee(): array
{
    test()->tenantJson('PATCH', '/api/admin/products/'.test()->coffee->id, ['is_subscribable' => true, 'subscription_discount_percent' => 10], test()->staff)
        ->assertOk()->assertJsonPath('data.is_subscribable', true)->assertJsonPath('data.subscription_discount_percent', '10.0000');

    return test()->tenantJson('POST', '/api/admin/products/'.test()->coffee->id.'/subscription-plans', ['interval' => 'monthly'], test()->staff)
        ->assertCreated()->assertJsonPath('data.label', 'Every month')->json('data');
}

it('starts a subscription with its first order, renews it at today\'s price with the saved card, and pauses, resumes and cancels', function (): void {
    // Plans need a subscribable product; one schedule is offered, one withdrawn.
    $this->tenantJson('POST', '/api/admin/products/'.$this->coffee->id.'/subscription-plans', ['interval' => 'monthly'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'product_not_subscribable');
    $plan = monthlyCoffee();
    $fortnight = $this->tenantJson('POST', '/api/admin/products/'.$this->coffee->id.'/subscription-plans', ['interval' => 'weekly', 'interval_count' => 2], $this->staff)
        ->assertCreated()->assertJsonPath('data.label', 'Every 2 weeks')->json('data');
    $this->tenantJson('DELETE', '/api/admin/products/'.$this->coffee->id."/subscription-plans/{$fortnight['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.is_active', false);

    $this->tenantJson('GET', '/api/products/coffee-beans/subscription-plans')->assertOk()
        ->assertJsonPath('data.discount_percent', '10.0000')->assertJsonCount(1, 'data.plans')->assertJsonPath('data.plans.0.id', $plan['id']);
    $this->tenantJson('GET', '/api/products/coffee-beans')->assertOk()->assertJsonPath('data.subscription.discount_percent', '10.0000');

    // A physical product needs a shipping method; a withdrawn plan is refused.
    $body = ['product_id' => $this->coffee->id, 'product_subscription_plan_id' => $plan['id'], 'quantity' => 2, 'address_id' => $this->address->id];
    $this->tenantJson('POST', '/api/account/product-subscriptions', $body, $this->adaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'shipping_method_required');
    $this->tenantJson('POST', '/api/account/product-subscriptions', [...$body, 'product_subscription_plan_id' => $fortnight['id'], 'shipping_method_id' => $this->method->id], $this->adaAuth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'subscription_plan_unavailable');

    // The first order: 2 × 10,000 less 10%, plus shipping; paid by Ada, saving the card.
    $created = $this->tenantJson('POST', '/api/account/product-subscriptions', [...$body, 'shipping_method_id' => $this->method->id], $this->adaAuth)
        ->assertCreated()->assertJsonPath('data.subscription.status', 'pending_payment')->assertJsonPath('data.subscription.has_stored_payment_method', false)->json('data');
    $order = $created['order'];
    expect($order)->toMatchArray(['status' => 'pending', 'subtotal' => '20000.0000', 'discount_amount' => '2000.0000', 'shipping_amount' => '1000.0000', 'total' => '19000.0000'])
        ->and($order['promotions'])->toBe([])
        ->and($order['payment_expires_at'])->not->toBeNull();
    tenancy()->initialize($this->tenant);
    expect(OrderItem::query()->where('order_id', $order['id'])->value('price_source'))->toBe('subscription')
        ->and((string) Inventory::query()->where('product_id', $this->coffee->id)->value('reserved_quantity'))->toBe('2.000');

    ($this->payFirstOrder)($order);
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/transaction/initialize'));

    $subscription = $this->tenantJson('GET', "/api/account/product-subscriptions/{$created['subscription']['id']}", [], $this->adaAuth)->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.has_stored_payment_method', true)
        ->assertJsonPath('data.next_billing_date', today()->addMonthsNoOverflow(1)->toDateString())
        ->assertJsonPath('data.orders.0.payment_status', 'paid')->json('data');
    $this->tenantJson('GET', "/api/account/product-subscriptions/{$subscription['id']}", [], $this->bolaAuth)->assertNotFound();
    $this->tenantJson('GET', '/api/account/product-subscriptions', [], $this->bolaAuth)->assertOk()->assertJsonCount(0, 'data');

    // From the next delivery: three bags, and the price has gone up.
    $this->tenantJson('PATCH', "/api/account/product-subscriptions/{$subscription['id']}", ['quantity' => 3], $this->adaAuth)->assertOk()->assertJsonPath('data.quantity', '3.000');
    tenancy()->initialize($this->tenant);
    $this->coffee->forceFill(['price' => '12000'])->save();
    expect(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(0);

    $this->travelTo(today()->addMonthsNoOverflow(1)->setTime(6, 0));
    subscriptionTokens();
    expect(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(1);
    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/transaction/charge_authorization')
        && $request['authorization_code'] === 'AUTH_test123' && $request['amount'] === 3340000);

    $renewed = $this->tenantJson('GET', "/api/admin/product-subscriptions/{$subscription['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.next_billing_date', today()->addMonthsNoOverflow(1)->toDateString())
        ->assertJsonPath('data.customer.id', $this->ada->id)->assertJsonCount(2, 'data.orders')->json('data');
    expect($renewed['orders'][1])->toMatchArray(['is_renewal' => true, 'payment_status' => 'paid', 'total' => '33400.0000', 'billing_date' => today()->toDateString()]);
    tenancy()->initialize($this->tenant);
    expect(Order::query()->findOrFail($renewed['orders'][1]['order_id'])->confirmed_at)->not->toBeNull();

    // Paused: nothing renews. Resumed: one period from today.
    $this->tenantJson('PATCH', "/api/account/product-subscriptions/{$subscription['id']}/pause", [], $this->adaAuth)->assertOk()->assertJsonPath('data.status', 'paused');
    $this->travel(40)->days();
    subscriptionTokens();
    expect(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(0);
    $this->tenantJson('PATCH', "/api/account/product-subscriptions/{$subscription['id']}/resume", [], $this->adaAuth)->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.next_billing_date', today()->addMonthsNoOverflow(1)->toDateString());

    // The module cannot be switched off while subscriptions run.
    $this->tenantJson('POST', '/api/admin/modules/product_subscriptions/disable', [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'module_disable_blocked');

    $strip = collect($this->tenantJson('GET', '/api/admin/product-subscriptions/metrics?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($strip)->toMatchArray(['active' => 1, 'paused' => 0, 'failed_renewals' => 0]);
    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/product_subscriptions?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    // 3 × 12,000 less 10%, once a month (an estimate before tax and shipping).
    expect($section)->toMatchArray(['active' => 1, 'recurring_value_per_month' => '32400.0000']);

    // Staff cancel: the customer is told, and the card is forgotten.
    $this->tenantJson('PATCH', "/api/admin/product-subscriptions/{$subscription['id']}/cancel", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.has_stored_payment_method', false);
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'product_subscription.cancelled'
        && str_contains($n->body, 'Coffee beans'));
    $this->tenantJson('PATCH', "/api/account/product-subscriptions/{$subscription['id']}/resume", [], $this->adaAuth)->assertStatus(422);
    $this->tenantJson('POST', '/api/admin/modules/product_subscriptions/disable', [], $this->staff)->assertOk();
});

it('retries a failed renewal the next day, releasing its stock, and cancels after the limit', function (): void {
    $plan = monthlyCoffee();
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('product_subscription_max_failed_renewals', 2);
    $subscription = ($this->adaSubscribes)($plan)['subscription'];

    $this->chargeSucceeds = false;
    $this->travelTo(today()->addMonthsNoOverflow(1)->setTime(6, 0));
    subscriptionTokens();
    app(ProductSubscriptionService::class)->processDueRenewals();

    $failed = $this->tenantJson('GET', "/api/admin/product-subscriptions/{$subscription['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'payment_failed')->assertJsonPath('data.failed_renewal_count', 1)
        ->assertJsonPath('data.last_renewal_error', 'payment_failed: Insufficient Funds')->json('data');
    // The renewal order is cancelled: its reservation goes back; the order that shipped keeps its deduction.
    expect($failed['orders'][1])->toMatchArray(['is_renewal' => true, 'status' => 'cancelled']);
    tenancy()->initialize($this->tenant);
    expect((string) Inventory::query()->where('product_id', $this->coffee->id)->value('reserved_quantity'))->toBe('0.000');
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'product_subscription.payment_failed'
        && str_contains($n->body, '/account/subscriptions/'.$subscription['id']));
    Notification::assertNotSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'order.payment_failed');

    // Retried the next day with a fresh order; the second failure reaches the limit.
    $this->travel(1)->days();
    subscriptionTokens();
    expect(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(1);
    $this->tenantJson('GET', "/api/admin/product-subscriptions/{$subscription['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.failed_renewal_count', 2)->assertJsonCount(3, 'data.orders')
        ->assertJsonPath('data.has_stored_payment_method', false);
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'product_subscription.cancelled');
    expect(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(0);
});

it('postpones a renewal it cannot make, and recovers without charging twice', function (): void {
    $plan = monthlyCoffee();
    $subscription = ($this->adaSubscribes)($plan, '15')['subscription'];

    // Only 5 bags left: the renewal waits, with the reason for staff; it is not a payment failure.
    $this->travelTo(today()->addMonthsNoOverflow(1)->setTime(6, 0));
    subscriptionTokens();
    expect(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(0);
    $this->tenantJson('GET', "/api/admin/product-subscriptions/{$subscription['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.failed_renewal_count', 0)
        ->assertJsonPath('data.last_renewal_error', 'stock_conflict: Coffee beans is not in stock for this quantity.');
    Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/transaction/charge_authorization'));

    // Restocked: renewed once, even when the job runs twice.
    tenancy()->initialize($this->tenant);
    app(InventoryService::class)->adjustStock($this->main, $this->coffee, null, '20', 'adjustment_in');
    expect(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(1)
        ->and(app(ProductSubscriptionService::class)->processDueRenewals())->toBe(0);
    expect(Http::recorded(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/transaction/charge_authorization')))->toHaveCount(1);
    $this->tenantJson('GET', "/api/admin/product-subscriptions?status=active&product_id={$this->coffee->id}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.id', $subscription['id'])->assertJsonPath('data.0.last_renewal_error', null)
        ->assertJsonPath('data.0.next_billing_date', today()->addMonthsNoOverflow(1)->toDateString());
});

it('cancels a subscription with its unpaid first order, and never starts one without a reusable card', function (): void {
    $plan = monthlyCoffee();
    $body = ['product_id' => $this->coffee->id, 'product_subscription_plan_id' => $plan['id'], 'address_id' => $this->address->id, 'shipping_method_id' => $this->method->id];

    // Cancelled before paying: the first order goes too, and its stock with it.
    $first = $this->tenantJson('POST', '/api/account/product-subscriptions', $body, $this->adaAuth)->assertCreated()->json('data');
    $this->tenantJson('DELETE', "/api/account/product-subscriptions/{$first['subscription']['id']}", [], $this->adaAuth)->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->tenantJson('GET', "/api/orders/{$first['order']['id']}", [], $this->adaAuth)->assertOk()->assertJsonPath('data.status', 'cancelled');

    // An order cancelled on its own takes its pending subscription with it.
    $second = $this->tenantJson('POST', '/api/account/product-subscriptions', $body, $this->adaAuth)->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/orders/{$second['order']['id']}/cancel", [], $this->adaAuth)->assertOk();
    $this->tenantJson('GET', "/api/account/product-subscriptions/{$second['subscription']['id']}", [], $this->adaAuth)->assertOk()->assertJsonPath('data.status', 'cancelled');

    // Paid with a card the provider cannot reuse: the order ships, the subscription does not start.
    $third = $this->tenantJson('POST', '/api/account/product-subscriptions', $body, $this->adaAuth)->assertCreated()->json('data');
    ($this->payFirstOrder)($third['order'], ['authorization' => ['authorization_code' => 'AUTH_once', 'reusable' => false]]);
    $this->tenantJson('GET', "/api/account/product-subscriptions/{$third['subscription']['id']}", [], $this->adaAuth)->assertOk()
        ->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.has_stored_payment_method', false)->assertJsonPath('data.orders.0.payment_status', 'paid');
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'product_subscription.payment_method_not_saved');

    // Erasure (§26.4) stops what is left and forgets saved cards.
    tenancy()->initialize($this->tenant);
    app(CustomerService::class)->deleteCustomer($this->ada);
    expect(CustomerSubscription::query()->where('customer_id', $this->ada->id)->where('status', '!=', 'cancelled')->count())->toBe(0);
});
