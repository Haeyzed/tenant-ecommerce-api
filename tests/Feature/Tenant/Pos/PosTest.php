<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\GiftCards\Services\GiftCardService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\RewardPoints\Services\RewardPointService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'standard');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(TenantSettingsService::class)->set('payment_mode', 'live');
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    // The shop floor is in Lagos: 7.5% VAT at the register's location.
    app(WarehouseService::class)->ensureDefault();
    $this->shop = Warehouse::query()->firstOrFail();
    $this->shop->forceFill(['country_id' => 1])->save();
    app(TaxService::class)->createTaxRate(['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => '7.5']);

    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40000', 'cost_price' => '25000', 'barcode' => '5901234123457', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->shop, $this->shoe, null, '5', 'adjustment_in');

    $this->walkIn = Customer::query()->create(['name' => 'Walk-in Customer', 'email' => 'walkin@a.test', 'password' => Str::random(20)]);
    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'phone' => '+2348000000001', 'password' => 'Secret123']);

    $this->tenantJson('POST', '/api/admin/modules/pos/enable', [], $this->staff)->assertOk();
    $this->tenantJson('PUT', '/api/admin/pos/settings', [
        'default_customer_id' => $this->walkIn->id,
        'enabled_payment_methods' => ['cash', 'bank_transfer', 'card_terminal', 'gift_card', 'reward_points', 'credit_sale'],
    ], $this->staff)->assertOk();

    $this->register = $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Front Counter'], $this->staff)
        ->assertCreated()->json('data');
});

function posSale(array $body, ?array $register = null): TestResponse
{
    return test()->tenantJson('POST', '/api/admin/pos/sales', [
        'register_id' => ($register ?? test()->register)['id'],
        'idempotency_key' => Str::uuid()->toString(),
        'lines' => [['product_id' => test()->shoe->id, 'quantity' => 1]],
        ...$body,
    ], test()->staff);
}

function posShoeStock(): string
{
    tenancy()->initialize(test()->tenant);

    return (string) Inventory::query()->where('product_id', test()->shoe->id)->value('quantity');
}

it('sells at the counter with change and split tender, then closes the drawer with its variance', function (): void {
    app(TenantSettingsService::class)->set('pos_cash_variance_threshold', '100');

    // No session yet: cash registers need one.
    posSale(['payments' => [['method' => 'cash', 'amount' => '43000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'session_required');

    $session = $this->tenantJson('POST', '/api/admin/pos/sessions', ['register_id' => $this->register['id'], 'opening_cash_float' => '10000'], $this->staff)
        ->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
    $this->tenantJson('POST', '/api/admin/pos/sessions', ['register_id' => $this->register['id'], 'opening_cash_float' => '0'], $this->staff)
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'session_already_open');

    // Scan, then quote: 2 Ã— 40,000 + 7.5% VAT at the shop.
    $this->tenantJson('GET', "/api/admin/pos/products/lookup?barcode=5901234123457&register_id={$this->register['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.product_id', $this->shoe->id)->assertJsonPath('data.0.price', '40000.0000')->assertJsonPath('data.0.stock', '5.000');
    $this->tenantJson('POST', '/api/admin/pos/quote', ['register_id' => $this->register['id'], 'lines' => [['product_id' => $this->shoe->id, 'quantity' => 2]]], $this->staff)
        ->assertOk()->assertJsonPath('data.subtotal', '80000.0000')->assertJsonPath('data.tax_amount', '6000.0000')->assertJsonPath('data.total', '86000.0000');

    // Cash with change; the walk-in customer is used.
    $key = Str::uuid()->toString();
    $sale = posSale(['idempotency_key' => $key, 'quote_total' => '86000', 'lines' => [['product_id' => $this->shoe->id, 'quantity' => 2]],
        'payments' => [['method' => 'cash', 'amount' => '100000']]])->assertCreated()->json('data');
    expect($sale['sale'])->toMatchArray(['order_source' => 'pos', 'status' => 'completed', 'payment_status' => 'paid', 'total' => '86000.0000', 'pos_session_id' => $session['id']])
        ->and($sale['sale']['customer']['id'])->toBe($this->walkIn->id)
        ->and($sale['receipt']['payments'][0])->toMatchArray(['method' => 'cash', 'amount_paid' => '86000.0000', 'amount_received' => '100000.0000', 'change_given' => '14000.0000'])
        ->and($sale['receipt']['register']['name'])->toBe('Front Counter')
        ->and(posShoeStock())->toBe('3.000');

    // The synced retry returns the same sale; no staff alert and no customer mail per till sale.
    posSale(['idempotency_key' => $key, 'payments' => [['method' => 'cash', 'amount' => '100000']]])->assertOk()->assertJsonPath('data.sale.id', $sale['sale']['id']);
    Notification::assertNotSentTo([$this->owner, $this->walkIn], TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => in_array($n->key, ['order.new_order_received', 'order.confirmed', 'pos.sale_receipt'], true));

    // Tenders: only cash may exceed the total; together they must cover it.
    posSale(['payments' => [['method' => 'cash', 'amount' => '1000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'payment_insufficient');
    posSale(['payments' => [['method' => 'bank_transfer', 'amount' => '50000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'payment_exceeds_total');
    posSale(['payments' => [['method' => 'cheque', 'amount' => '43000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'payment_method_disabled');
    posSale(['quote_total' => '40000', 'payments' => [['method' => 'cash', 'amount' => '43000']]])->assertStatus(409)->assertJsonPath('meta.error_code', 'totals_changed');
    posSale(['lines' => [['product_id' => $this->shoe->id, 'quantity' => 4]], 'payments' => [['method' => 'cash', 'amount' => '172000']]])
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'stock_conflict');

    posSale(['payments' => [['method' => 'bank_transfer', 'amount' => '20000', 'reference' => 'TRF-88'], ['method' => 'cash', 'amount' => '23000']]])
        ->assertCreated()->assertJsonPath('data.sale.payment_status', 'paid')->assertJsonCount(2, 'data.sale.payments');

    // Opening 10,000 + cash 86,000 + 23,000 = 119,000 expected; 118,500 counted.
    $closed = $this->tenantJson('POST', "/api/admin/pos/sessions/{$session['id']}/close", ['closing_cash_float' => '118500'], $this->staff)->assertOk()->json('data');
    expect($closed)->toMatchArray(['status' => 'closed', 'expected_cash' => '119000.0000', 'cash_variance' => '-500.0000'])
        ->and($closed['summary'])->toMatchArray(['sales_count' => 2, 'sales_total' => '129000.0000', 'cash_in' => '109000.0000'])
        ->and($closed['summary']['payments_by_method']['bank_transfer'])->toBe('20000.0000');
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'pos.session_variance_flagged');

    posSale(['payments' => [['method' => 'cash', 'amount' => '43000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'session_required');
    $this->tenantJson('GET', "/api/admin/pos/sales?session_id={$session['id']}", [], $this->staff)->assertOk()->assertJsonCount(2, 'data');
});

it('voids a sale while its session is open: refunds, restocks and returns the gift card and points', function (): void {
    tenancy()->initialize($this->tenant);
    app(RewardPointService::class)->updateSettings(['is_active' => true, 'amount_per_point' => '1000', 'redeem_amount_per_point' => '10']);
    app(RewardPointService::class)->adjustPoints($this->ada, 200, 'Welcome');
    $card = app(GiftCardService::class)->issueGiftCard(['initial_value' => '5000']);
    $this->tenantJson('POST', '/api/admin/modules/gift_cards/enable', [], $this->staff)->assertOk();
    $this->tenantJson('POST', '/api/admin/modules/reward_points/enable', [], $this->staff)->assertOk();
    $session = $this->tenantJson('POST', '/api/admin/pos/sessions', ['register_id' => $this->register['id'], 'opening_cash_float' => '0'], $this->staff)->assertCreated()->json('data');

    // The walk-in cannot use points or buy on credit.
    posSale(['reward_points' => 100, 'payments' => [['method' => 'cash', 'amount' => '43000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'customer_required');
    posSale(['credit_sale' => true, 'payments' => []])->assertStatus(422)->assertJsonPath('meta.error_code', 'customer_required');

    // 43,000 âˆ’ 1,000 (100 points) = 42,000: 5,000 on the gift card, the rest in cash.
    $sale = posSale(['customer_id' => $this->ada->id, 'reward_points' => 100, 'payments' => [
        ['method' => 'gift_card', 'amount' => '5000', 'gift_card_code' => strtolower($card->code)],
        ['method' => 'cash', 'amount' => '37000'],
    ]])->assertCreated()->json('data.sale');
    expect($sale)->toMatchArray(['total' => '42000.0000', 'reward_points_discount_amount' => '1000.0000', 'payment_status' => 'paid']);

    tenancy()->initialize($this->tenant);
    // Earned on completion: (40,000 âˆ’ 1,000) / 1,000 = 39.
    expect(app(RewardPointService::class)->getBalance($this->ada))->toBe(139)
        ->and((string) $card->refresh()->current_balance)->toBe('0.0000')
        ->and(posShoeStock())->toBe('4.000');

    $voided = $this->tenantJson('POST', "/api/admin/pos/sales/{$sale['id']}/void", ['reason' => 'Wrong size'], $this->staff)->assertOk()->json('data');
    expect($voided)->toMatchArray(['status' => 'refunded', 'payment_status' => 'refunded', 'cancellation_reason' => 'Wrong size']);

    tenancy()->initialize($this->tenant);
    expect(posShoeStock())->toBe('5.000')
        ->and((string) $card->refresh()->current_balance)->toBe('5000.0000')
        ->and(app(RewardPointService::class)->getBalance($this->ada))->toBe(200)
        ->and((string) OrderPayment::query()->where('order_id', $sale['id'])->where('kind', 'refund')->where('payment_method', 'cash')->value('amount_paid'))->toBe('-37000.0000');
    $this->tenantJson('POST', "/api/admin/pos/sales/{$sale['id']}/void", ['reason' => 'Again'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'sale_not_voidable');

    // Credit sale: confirmed with the balance still due.
    $credit = posSale(['customer_id' => $this->ada->id, 'credit_sale' => true, 'payments' => [['method' => 'cash', 'amount' => '10000']]])
        ->assertCreated()->assertJsonPath('data.sale.payment_status', 'partially_paid')->json('data.sale');
    expect($credit['confirmed_at'])->not->toBeNull()->and(posShoeStock())->toBe('4.000');

    // After the session closes, a sale can only be returned.
    $this->tenantJson('POST', "/api/admin/pos/sessions/{$session['id']}/close", ['closing_cash_float' => '10000'], $this->staff)->assertOk()
        ->assertJsonPath('data.expected_cash', '10000.0000')->assertJsonPath('data.cash_variance', '0.0000');
    $this->tenantJson('POST', "/api/admin/pos/sales/{$credit['id']}/void", ['reason' => 'Late'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'void_window_closed');
});

it('takes card payments through the register terminal, once each, and reverses them on void', function (): void {
    $this->tenantJson('PUT', '/api/admin/pos/settings', ['cash_register_enabled' => false], $this->staff)->assertOk();
    $this->tenantJson('PATCH', "/api/admin/pos/registers/{$this->register['id']}/terminal", ['provider' => 'opay', 'credentials' => ['merchant_id' => '256600000000001']], $this->staff)
        ->assertStatus(422);
    $saved = $this->tenantJson('PATCH', "/api/admin/pos/registers/{$this->register['id']}/terminal", ['provider' => 'opay', 'credentials' => [
        'merchant_id' => '256600000000001', 'secret_key' => 'OPAY-SECRET-KEY', 'terminal_serial' => 'N78101818218',
    ]], $this->staff)->assertOk()->assertJsonPath('data.terminal_provider', 'opay')->assertJsonPath('data.has_terminal_credentials', true);
    expect($saved->getContent())->not->toContain('OPAY-SECRET-KEY');

    Http::fake([
        'liveapi.opaycheckout.com/api/v1/international/payment/create' => Http::response(['code' => '00000', 'message' => 'SUCCESSFUL', 'data' => ['orderNo' => '220110144664537659', 'status' => 'PENDING', 'nextAction' => ['actionType' => 'SWIPE_CARD']]]),
        'liveapi.opaycheckout.com/api/v1/international/cashier/status' => Http::response(['code' => '00000', 'data' => ['orderNo' => '220110144664537659', 'status' => 'SUCCESS', 'amount' => ['total' => 4300000, 'currency' => 'NGN']]]),
        'liveapi.opaycheckout.com/api/v1/international/payment/refund/create' => Http::response(['code' => '00000', 'data' => ['orderNo' => '211003140885499643', 'orderStatus' => 'SUCCESS']]),
        'channel.moniepoint.com/v1/auth' => Http::response(['accessToken' => 'mp-token', 'expiresIn' => 3600]),
        'channel.moniepoint.com/v1/transactions/merchants/*' => Http::response(['transactionStatus' => 'APPROVED', 'actualAmount' => 4300000, 'transactionReference' => 'MP-RRN-1']),
        'channel.moniepoint.com/v1/transactions' => Http::response([], 202),
    ]);

    // Terminals take real money only.
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('payment_mode', 'test');
    $this->tenantJson('POST', '/api/admin/pos/terminal-charges', ['register_id' => $this->register['id'], 'amount' => '43000'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'payment_mode_mismatch');
    app(TenantSettingsService::class)->set('payment_mode', 'live');

    $charge = $this->tenantJson('POST', '/api/admin/pos/terminal-charges', ['register_id' => $this->register['id'], 'amount' => '43000'], $this->staff)
        ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
    $this->tenantJson('GET', "/api/admin/pos/terminal-charges/{$charge['reference']}?register_id={$this->register['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'successful')->assertJsonPath('data.provider_reference', '220110144664537659');
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/payment/create') && $request->hasHeader('MerchantId', '256600000000001')
        && $request->header('Authorization')[0] === 'Bearer '.hash_hmac('sha512', $request->body(), 'OPAY-SECRET-KEY')
        && $request['sn'] === 'N78101818218' && $request['amount']['total'] === 4300000);

    // No session needed with cash registers off.
    $sale = posSale(['payments' => [['method' => 'card_terminal', 'amount' => '43000', 'reference' => $charge['reference']]]])->assertCreated()->json('data');
    expect($sale['sale']['pos_session_id'])->toBeNull()->and($sale['sale']['payments'][0])->toMatchArray(['payment_method' => 'card_terminal', 'provider' => 'opay']);
    posSale(['payments' => [['method' => 'card_terminal', 'amount' => '43000', 'reference' => $charge['reference']]]])->assertStatus(409)->assertJsonPath('meta.error_code', 'terminal_charge_used');

    $this->tenantJson('POST', "/api/admin/pos/sales/{$sale['sale']['id']}/void", ['reason' => 'Changed mind'], $this->staff)->assertOk()->assertJsonPath('data.status', 'refunded');
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/refund/create') && $request['originalReference'] === $charge['reference']);

    // Moniepoint has no refund API: the cashier reverses on the device, then confirms.
    $till = $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Till 2'], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('PATCH', "/api/admin/pos/registers/{$till['id']}/terminal", ['provider' => 'moniepoint', 'credentials' => [
        'client_id' => 'mp-client', 'client_secret' => 'mp-secret', 'terminal_serial' => 'P260300061091',
    ]], $this->staff)->assertOk();
    $mp = $this->tenantJson('POST', '/api/admin/pos/terminal-charges', ['register_id' => $till['id'], 'amount' => '43000'], $this->staff)->assertCreated()->json('data');
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/transactions') && $request->hasHeader('Authorization', 'Bearer mp-token')
        && $request['terminalSerial'] === 'P260300061091' && $request['merchantReference'] === $mp['reference']);

    $mpSale = posSale(['payments' => [['method' => 'card_terminal', 'amount' => '43000', 'reference' => $mp['reference']]]], $till)->assertCreated()->json('data.sale');
    $this->tenantJson('POST', "/api/admin/pos/sales/{$mpSale['id']}/void", ['reason' => 'Refund'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'terminal_refund_unsupported');
    $this->tenantJson('POST', "/api/admin/pos/sales/{$mpSale['id']}/void", ['reason' => 'Refund', 'terminal_reversed' => true], $this->staff)->assertOk()->assertJsonPath('data.status', 'refunded');
});

it('syncs an offline sale once, into the session it was made in, and flags a promotion pushed past its limit', function (): void {
    tenancy()->initialize($this->tenant);
    $promotion = app(PromotionService::class)->createPromotion(['name' => 'Opening week', 'trigger' => 'automatic', 'scope' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10, 'usage_limit_total' => 1]);
    $session = $this->tenantJson('POST', '/api/admin/pos/sessions', ['register_id' => $this->register['id'], 'opening_cash_float' => '0'], $this->staff)->assertCreated()->json('data');

    // Online at the counter: 40,000 âˆ’ 10% + 7.5% VAT = 38,700; the promotion is used up.
    posSale(['payments' => [['method' => 'cash', 'amount' => '38700']]])->assertCreated()->assertJsonPath('data.sale.total', '38700.0000');

    $this->travel(20)->minutes();
    $this->tenantJson('POST', "/api/admin/pos/sessions/{$session['id']}/close", ['closing_cash_float' => '77400'], $this->staff)->assertOk()
        ->assertJsonPath('data.cash_variance', '38700.0000');

    // A sale made offline 10 minutes ago, before the close, with the same discount.
    $key = Str::uuid()->toString();
    $body = ['idempotency_key' => $key, 'pos_session_id' => $session['id'], 'sold_at' => now()->subMinutes(10)->toIso8601String(), 'quote_total' => '38700',
        'payments' => [['method' => 'cash', 'amount' => '38700']]];
    $offline = posSale($body)->assertCreated()->json('data.sale');
    posSale($body)->assertOk()->assertJsonPath('data.sale.id', $offline['id']);

    tenancy()->initialize($this->tenant);
    $row = Order::query()->findOrFail($offline['id']);
    expect($row->placed_at->toIso8601String())->toBe($body['sold_at'])
        ->and(PromotionRedemption::query()->where('order_id', $row->id)->value('over_limit'))->toBeTrue();
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'promotion.limit_exceeded_offline');
    expect($promotion->refresh()->times_redeemed)->toBe(2);

    // Its cash was in the counted drawer: the variance is recomputed.
    $this->tenantJson('GET', "/api/admin/pos/sessions/{$session['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.expected_cash', '77400.0000')->assertJsonPath('data.cash_variance', '0.0000');

    // Too old, or out of stock: flagged for staff, never dropped.
    posSale(['pos_session_id' => $session['id'], 'sold_at' => now()->subDays(5)->toIso8601String(), 'payments' => [['method' => 'cash', 'amount' => '38700']]])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'sale_time_invalid');
    posSale(['pos_session_id' => $session['id'], 'sold_at' => now()->subMinutes(5)->toIso8601String(), 'lines' => [['product_id' => $this->shoe->id, 'quantity' => 9]],
        'payments' => [['method' => 'cash', 'amount' => '400000']]])->assertStatus(409)->assertJsonPath('meta.error_code', 'stock_conflict');
});

it('keeps registers within the plan and the warehouse ceiling', function (): void {
    // Standard allows 3 active registers.
    $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Till 2'], $this->staff)->assertCreated();
    $third = $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Till 3'], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Till 4'], $this->staff)
        ->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');

    $this->tenantJson('DELETE', "/api/admin/pos/registers/{$third['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.is_active', false);
    $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Till 4'], $this->staff)->assertCreated();
    $this->tenantJson('PATCH', "/api/admin/pos/registers/{$third['id']}/activate", [], $this->staff)->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');

    // A register with an open session stays active.
    $this->tenantJson('POST', '/api/admin/pos/sessions', ['register_id' => $this->register['id'], 'opening_cash_float' => '0'], $this->staff)->assertCreated();
    $this->tenantJson('DELETE', "/api/admin/pos/registers/{$this->register['id']}", [], $this->staff)->assertStatus(409)->assertJsonPath('meta.error_code', 'session_open');
    $this->tenantJson('GET', "/api/admin/pos/registers/{$this->register['id']}/current-session", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'open');
});
