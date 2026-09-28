<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\GiftCards\Jobs\ExpireGiftCards;
use App\Modules\GiftCards\Models\GiftCard;
use App\Modules\GiftCards\Services\GiftCardService;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Services\InstallmentPlanService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Jobs\VerifyOrderPayment;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\RewardPoints\Services\RewardPointService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    // Test orders neither use nor issue real stored value (§40.8); each test says when it rehearses.
    app(TenantSettingsService::class)->set('payment_mode', 'live');
    User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true])->assignRole('owner');
    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    freshTokens();

    // Digital, so checkout needs no address or shipping.
    $this->guide = Product::query()->create(['name' => 'Guide', 'price' => '5000', 'product_type' => 'digital', 'is_active' => true]);

    foreach (['gift_cards', 'reward_points', 'installments'] as $module) {
        $this->tenantJson('POST', "/api/admin/modules/{$module}/enable", [], $this->staff)->assertOk();
    }
});

/**
 * Staff and Ada tokens; minted again after travelling past their expiry.
 */
function freshTokens(): void
{
    tenancy()->initialize(test()->tenant);
    test()->staff = ['Authorization' => 'Bearer '.User::query()->where('email', 'owner@a.test')->firstOrFail()->createToken('t', ['staff'])->plainTextToken];
    test()->adaAuth = ['Authorization' => 'Bearer '.test()->ada->createToken('t', ['customer'])->plainTextToken];
}

/**
 * Ada's cart with the guide, the given callback applied, then quoted.
 *
 * @return array<string, mixed> the quote
 */
function adaQuote(int $quantity = 1, ?Closure $prepare = null): array
{
    test()->tenantJson('POST', '/api/cart/items', ['product_id' => test()->guide->id, 'quantity' => $quantity], test()->adaAuth)->assertCreated();

    if ($prepare !== null) {
        $prepare();
    }

    return test()->tenantJson('GET', '/api/cart', [], test()->adaAuth)->assertOk()->json('data.quote');
}

function adaCheckout(array $quote, array $extra = []): TestResponse
{
    return test()->tenantJson('POST', '/api/orders', ['quote_hash' => $quote['quote_hash'], ...$extra], [...test()->adaAuth, 'Idempotency-Key' => Str::uuid()->toString()]);
}

function payCash(int $orderId, string $amount): void
{
    test()->tenantJson('POST', "/api/admin/orders/{$orderId}/payments", ['payment_method' => 'cash', 'amount' => $amount], [...test()->staff, 'Idempotency-Key' => Str::uuid()->toString()])->assertCreated();
}

it('issues a gift card once, pays part of an order with it and credits it back when the order is cancelled', function (): void {
    $card = $this->tenantJson('POST', '/api/admin/gift-cards', ['initial_value' => '3000', 'recipient_email' => 'Bola@Shop.test'], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertCreated()->assertJsonPath('data.current_balance', '3000.0000')->assertJsonPath('data.currency_code', 'NGN')->json('data');
    $masked = '…'.substr($card['code'], -4);

    // The full code is shown once; lists show the last four characters.
    expect($card['code'])->toMatch('/^[A-Z2-9]{16}$/');
    $this->tenantJson('GET', '/api/admin/gift-cards', [], $this->staff)->assertOk()->assertJsonPath('data.0.code', $masked);
    Notification::assertSentOnDemand(TemplatedNotification::class, fn (TemplatedNotification $n, array $channels, AnonymousNotifiable $to): bool => $n->key === 'gift_card.issued'
        && $to->routes['mail'] === 'bola@shop.test' && str_contains($n->body, $card['code']));

    $this->tenantJson('GET', "/api/gift-cards/{$card['code']}/balance")->assertOk()->assertJsonPath('data.balance', '3000.0000')->assertJsonPath('data.status', 'active');
    $this->tenantJson('GET', '/api/gift-cards/NOSUCHCARD123/balance')->assertNotFound()->assertJsonPath('meta.error_code', 'gift_card_not_found');

    // The card pays part of the order: the quote shows what is left to pay.
    $quote = adaQuote(1, fn () => $this->tenantJson('POST', '/api/cart/apply-gift-card', ['code' => strtolower($card['code'])], $this->adaAuth)->assertOk());
    expect($quote)->toMatchArray(['gift_card_applied' => true, 'total' => '5000.0000', 'gift_card_amount_applied' => '3000.0000', 'amount_due' => '2000.0000']);

    $order = adaCheckout($quote)->assertCreated()->json('data');

    tenancy()->initialize($this->tenant);
    $row = Order::query()->findOrFail($order['id']);
    $model = GiftCard::query()->findOrFail($card['id']);
    expect($row->payment_status)->toBe('partially_paid')
        ->and((string) $row->gift_card_amount_applied)->toBe('3000.0000')
        ->and($model->status)->toBe(GiftCard::REDEEMED)->and((string) $model->current_balance)->toBe('0.0000')
        ->and(OrderPayment::query()->where('order_id', $row->id)->where('payment_method', 'gift_card')->value('amount_paid'))->toEqual('3000.0000');
    $this->tenantJson('GET', "/api/admin/gift-cards/{$card['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.redemptions.0.amount', '3000.0000');

    // Paid only with stored value, the customer may still cancel; the card is whole again.
    $this->tenantJson('POST', "/api/orders/{$order['id']}/cancel", [], $this->adaAuth)->assertOk()->assertJsonPath('data.status', 'cancelled');

    tenancy()->initialize($this->tenant);
    $model->refresh();
    expect($model->status)->toBe(GiftCard::ACTIVE)->and((string) $model->current_balance)->toBe('3000.0000')
        ->and(Order::query()->findOrFail($order['id'])->payment_status)->toBe('refunded')
        ->and((string) Order::query()->findOrFail($order['id'])->gift_card_amount_applied)->toBe('0.0000');

    // A disabled card is refused on the cart.
    $this->tenantJson('PATCH', "/api/admin/gift-cards/{$card['id']}/disable", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'disabled');
    $this->tenantJson('POST', '/api/cart/items', ['product_id' => $this->guide->id, 'quantity' => 1], $this->adaAuth)->assertCreated();
    $this->tenantJson('POST', '/api/cart/apply-gift-card', ['code' => $card['code']], $this->adaAuth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'gift_card_invalid')->assertJsonPath('meta.details.reason', 'disabled');
});

it('sells a gift card as an order and issues it when the order is paid; cards expire', function (): void {
    // A rehearsal (test order) issues no card.
    app(TenantSettingsService::class)->set('payment_mode', 'test');
    $rehearsal = $this->tenantJson('POST', '/api/gift-cards/purchase', ['amount' => '2000'], [...$this->adaAuth, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertCreated()->assertJsonPath('data.is_test', true)->json('data');
    payCash($rehearsal['id'], '2000');

    tenancy()->initialize($this->tenant);
    expect(Order::query()->findOrFail($rehearsal['id'])->payment_status)->toBe('paid');
    app(TenantSettingsService::class)->set('payment_mode', 'live');

    $order = $this->tenantJson('POST', '/api/gift-cards/purchase', ['amount' => '10000', 'recipient_email' => 'bola@shop.test', 'recipient_message' => 'Enjoy'], [...$this->adaAuth, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertCreated()->assertJsonPath('data.order_type', 'gift_card_purchase')->assertJsonPath('data.total', '10000.0000')
        ->assertJsonPath('data.items.0.product', null)->json('data');

    tenancy()->initialize($this->tenant);
    expect(GiftCard::query()->count())->toBe(0);

    payCash($order['id'], '10000');

    tenancy()->initialize($this->tenant);
    expect(Order::query()->findOrFail($order['id'])->only(['payment_status', 'status', 'order_type']))->toBe(['payment_status' => 'paid', 'status' => 'delivered', 'order_type' => 'gift_card_purchase']);
    $card = GiftCard::query()->where('source_order_id', $order['id'])->firstOrFail();
    expect((string) $card->initial_value)->toBe('10000.0000')
        ->and($card->recipient_email)->toBe('bola@shop.test')
        ->and($card->purchased_by_customer_id)->toBe($this->ada->id)
        ->and(Order::query()->findOrFail($order['id'])->status)->toBe('delivered');
    Notification::assertSentOnDemand(TemplatedNotification::class, fn (TemplatedNotification $n, array $channels, AnonymousNotifiable $to): bool => $n->key === 'gift_card.issued'
        && $to->routes['mail'] === 'bola@shop.test' && str_contains($n->body, $card->code) && str_contains($n->body, 'Enjoy'));

    // Confirmation issues one card only, whatever runs again.
    app(GiftCardService::class)->issueForOrder(Order::query()->findOrFail($order['id']));
    expect(GiftCard::query()->count())->toBe(1);

    $short = app(GiftCardService::class)->issueGiftCard(['initial_value' => '500', 'expires_at' => now()->addDay()->toIso8601String()]);
    $this->travel(2)->days();
    (new ExpireGiftCards)->handle(app(GiftCardService::class));
    expect($short->refresh()->status)->toBe(GiftCard::EXPIRED)->and($card->refresh()->status)->toBe(GiftCard::ACTIVE);
});

it('redeems reward points as a discount, earns on completion, restores on cancel and expires them', function (): void {
    $this->tenantJson('PUT', '/api/admin/reward-points/settings', [
        'is_active' => true, 'amount_per_point' => '100', 'redeem_amount_per_point' => '10', 'minimum_redeem_points' => 10, 'point_expiry_days' => 30,
    ], $this->staff)->assertOk()->assertJsonPath('data.is_active', true);
    $this->tenantJson('POST', "/api/admin/customers/{$this->ada->id}/reward-points/adjust", ['points' => 200, 'reason' => 'Welcome'], $this->staff)
        ->assertOk()->assertJsonPath('data.points_balance', 200);

    $this->tenantJson('POST', '/api/cart/items', ['product_id' => $this->guide->id, 'quantity' => 1], $this->adaAuth)->assertCreated();

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('payment_mode', 'test');
    $this->tenantJson('POST', '/api/cart/apply-reward-points', ['points' => 100], $this->adaAuth)->assertStatus(422)->assertJsonPath('meta.details.reason', 'test_mode');
    app(TenantSettingsService::class)->set('payment_mode', 'live');

    $this->tenantJson('POST', '/api/cart/apply-reward-points', ['points' => 5], $this->adaAuth)->assertStatus(422)->assertJsonPath('meta.details.reason', 'below_minimum_points');
    $this->tenantJson('POST', '/api/cart/apply-reward-points', ['points' => 1000], $this->adaAuth)->assertStatus(422)->assertJsonPath('meta.details.reason', 'insufficient_points');
    $this->tenantJson('POST', '/api/cart/apply-reward-points', ['points' => 100], $this->adaAuth)->assertOk();

    // 100 points × 10 = 1000 off.
    $quote = $this->tenantJson('GET', '/api/cart', [], $this->adaAuth)->assertOk()->json('data.quote');
    expect($quote)->toMatchArray(['reward_points_redeemed' => 100, 'reward_points_discount_amount' => '1000.0000', 'total' => '4000.0000']);

    $order = adaCheckout($quote)->assertCreated()->json('data');
    $this->tenantJson('GET', '/api/account/reward-points/balance', [], $this->adaAuth)->assertOk()->assertJsonPath('data.points_balance', 100);

    // Paid, the digital order is delivered at once: (5000 − 1000) / 100 = 40 points.
    payCash($order['id'], '4000');
    $this->tenantJson('GET', '/api/account/reward-points/balance', [], $this->adaAuth)->assertOk()->assertJsonPath('data.points_balance', 140);

    tenancy()->initialize($this->tenant);
    app(RewardPointService::class)->earnPoints(Order::query()->findOrFail($order['id']));
    expect(app(RewardPointService::class)->getBalance($this->ada))->toBe(140);

    // Points on a cancelled order come back.
    $second = adaCheckout(adaQuote(1, fn () => $this->tenantJson('POST', '/api/cart/apply-reward-points', ['points' => 50], $this->adaAuth)->assertOk()))->assertCreated()->json('data');
    $this->tenantJson('GET', '/api/account/reward-points/balance', [], $this->adaAuth)->assertJsonPath('data.points_balance', 90);
    $this->tenantJson('POST', "/api/orders/{$second['id']}/cancel", [], $this->adaAuth)->assertOk();
    $this->tenantJson('GET', '/api/account/reward-points/balance', [], $this->adaAuth)->assertJsonPath('data.points_balance', 140);

    // After 30 days, the 40 earned points expire; adjustments do not.
    $this->travel(31)->days();
    freshTokens();
    expect(app(RewardPointService::class)->expirePoints())->toBe(40)
        ->and(app(RewardPointService::class)->expirePoints())->toBe(0)
        ->and(app(RewardPointService::class)->getBalance($this->ada))->toBe(100);
    $this->tenantJson('GET', '/api/account/reward-points/history', [], $this->adaAuth)->assertOk()->assertJsonPath('data.0.type', 'expired')->assertJsonPath('data.0.points', -40);

    // A guest cannot use points.
    $token = $this->tenantJson('POST', '/api/cart/items', ['product_id' => $this->guide->id, 'quantity' => 1])->assertCreated()->json('data.guest_token');
    $this->tenantJson('POST', '/api/cart/apply-reward-points', ['points' => 10], ['X-Guest-Token' => $token])->assertUnauthorized();
});

it('splits an order into installments, stores the authorization on the first payment and charges the rest', function (): void {
    Bus::fake([VerifyOrderPayment::class]);
    tenancy()->initialize($this->tenant);
    TenantPaymentSetting::query()->create(['provider' => 'paystack', 'mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => self::PAYSTACK_TEST_SECRET, 'is_active' => true, 'enabled_for_online' => true]);
    // Rehearsed with the provider's test credentials: a test order, charged in test mode.
    app(TenantSettingsService::class)->set('payment_mode', 'test');
    app(TenantSettingsService::class)->set('installments_fulfillment_policy', 'on_first_payment');
    app(TenantSettingsService::class)->set('installments_default_after_overdue_count', 1);

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/x', 'access_code' => 'x', 'reference' => 'r']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'ongoing', 'amount' => 0, 'currency' => 'NGN']]),
        'api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success', 'id' => 555]]),
    ]);

    // The store has not switched installments on.
    $quote = adaQuote(2);
    adaCheckout($quote, ['installment_plan' => ['number_of_installments' => 3, 'frequency' => 'monthly']])
        ->assertForbidden()->assertJsonPath('meta.error_code', 'installments_unavailable');

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('installments_enabled', true);
    $order = adaCheckout($quote, ['installment_plan' => ['number_of_installments' => 3, 'frequency' => 'monthly']])->assertCreated()
        ->assertJsonPath('data.payment_expires_at', null)->json('data');

    // 10000 / 3: the remainder goes on the last installment.
    $plan = $this->tenantJson('GET', "/api/orders/{$order['id']}/installment-plan", [], $this->adaAuth)->assertOk()->json('data');
    expect(collect($plan['payments'])->pluck('amount_due')->all())->toBe(['3333.3300', '3333.3300', '3333.3400'])
        ->and(collect($plan['payments'])->pluck('due_date')->all())->toBe([today()->toDateString(), today()->addMonthsNoOverflow(1)->toDateString(), today()->addMonthsNoOverflow(2)->toDateString()])
        ->and($plan['has_stored_payment_method'])->toBeFalse();

    $this->tenantJson('GET', "/api/orders/{$order['id']}/installment-eligibility", [], $this->adaAuth)->assertOk()->assertJsonPath('data.reason', 'plan_exists');
    $this->tenantJson('POST', "/api/orders/{$order['id']}/pay", ['gateway' => 'paystack'], [...$this->adaAuth, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'installment_plan_active');

    // A staff charge needs a stored authorization first.
    [$first, $second, $third] = $plan['payments'];
    $this->tenantJson('POST', "/api/admin/installment-plans/{$plan['id']}/payments/{$second['id']}/charge", [], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'no_stored_authorization');

    // The customer pays the first installment; the webhook saves the authorization.
    $pay = $this->tenantJson('POST', "/api/installment-payments/{$first['id']}/pay", ['gateway' => 'paystack'], [...$this->adaAuth, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.paystack.test/x')->json('data');
    $body = (string) json_encode($this->paystackChargeSuccess($pay['reference'], 333333, 'NGN'));
    $this->call('POST', 'http://'.$this->landlordHost()."/api/webhooks/{$this->tenant->id}/paystack/test", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::PAYSTACK_TEST_SECRET),
    ], $body)->assertOk();

    tenancy()->initialize($this->tenant);
    $model = InstallmentPlan::query()->findOrFail($plan['id']);
    $row = Order::query()->findOrFail($order['id']);
    expect($model->authorization_token)->toBe('AUTH_test123')->and($model->payment_provider)->toBe('paystack')
        ->and($row->payment_status)->toBe('partially_paid')
        ->and($row->confirmed_at)->not->toBeNull(); // on_first_payment

    // Staff charge the second installment early through the stored authorization.
    $this->tenantJson('POST', "/api/admin/installment-plans/{$plan['id']}/payments/{$second['id']}/charge", [], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertOk()->assertJsonPath('data.payment.status', 'successful')->assertJsonPath('data.plan.payments.1.status', 'paid')
        ->assertJsonPath('data.plan.has_stored_payment_method', true);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/transaction/charge_authorization')
        && $request['authorization_code'] === 'AUTH_test123' && $request['amount'] === 333333);

    // Nothing more is due until the third date; a day after it, the plan is flagged.
    tenancy()->initialize($this->tenant);
    expect(app(InstallmentPlanService::class)->chargeDueInstallments())->toBe(0);
    $this->travelTo(today()->addMonthsNoOverflow(2)->addDay()->setTime(9, 0));
    freshTokens();
    expect(app(InstallmentPlanService::class)->markOverdueInstallments())->toBe(1);
    expect(InstallmentPlan::query()->findOrFail($plan['id'])->status)->toBe(InstallmentPlan::DEFAULTED);

    $this->tenantJson('GET', '/api/admin/installment-plans?status=defaulted', [], $this->staff)->assertOk()->assertJsonPath('data.0.id', $plan['id'])
        ->assertJsonPath('data.0.payments.2.status', 'overdue');
    $this->tenantJson('PATCH', "/api/admin/installment-plans/{$plan['id']}/cancel", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.has_stored_payment_method', false);
    $this->tenantJson('POST', "/api/installment-payments/{$third['id']}/pay", ['gateway' => 'paystack'], [...$this->adaAuth, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'installment_plan_closed');
});
