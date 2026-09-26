<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Promotions\Jobs\GenerateCouponCodes;
use App\Modules\Promotions\Models\Coupon;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Services\CouponService;
use App\Modules\Promotions\Services\PromotionEngine;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\Promotions\Support\FlashSalePrices;
use App\Modules\Promotions\Support\PricingContext;
use App\Modules\Promotions\Support\PricingLine;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    $apparel = Category::query()->create(['name' => 'Apparel']);
    $this->shoes = Category::query()->create(['name' => 'Shoes', 'parent_id' => $apparel->id]);
    $this->apparel = $apparel;
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'is_active' => true]);
    $this->shoe->categories()->attach($this->shoes->id, ['is_primary' => true]);
    $this->mug = Product::query()->create(['name' => 'Mug', 'price' => '10', 'is_active' => true]);
});

function promo(array $data): Promotion
{
    return app(PromotionService::class)->createPromotion(array_merge(['name' => 'Promo', 'trigger' => 'automatic', 'scope' => 'line', 'discount_type' => 'percentage', 'discount_value' => 10], $data));
}

/**
 * A bare order row for redemptions to point at (their order foreign key).
 */
function promoOrderId(): int
{
    return (int) DB::connection('tenant')->table('orders')->insertGetId([
        'order_number' => 'T'.random_int(100000, 999999), 'status' => 'pending', 'currency_code' => 'NGN',
        'subtotal' => 0, 'total' => 0, 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function basket(array $overrides = []): PricingContext
{
    $lines = [
        new PricingLine(0, test()->shoe, null, '2', '40.0000'),
        new PricingLine(1, test()->mug, null, '3', '10.0000'),
    ];

    return new PricingContext(...array_merge(['lines' => $lines, 'currency' => 'NGN'], $overrides));
}

it('validates promotion rules and keeps used rules frozen', function (): void {
    $this->tenantJson('POST', '/api/admin/promotions', ['name' => 'X', 'trigger' => 'automatic', 'scope' => 'order', 'discount_type' => 'free_shipping'], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('scope');
    $this->tenantJson('POST', '/api/admin/promotions', ['name' => 'X', 'trigger' => 'automatic', 'scope' => 'line', 'discount_type' => 'percentage', 'discount_value' => 120], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('discount_value');
    $this->tenantJson('POST', '/api/admin/promotions', ['name' => 'X', 'trigger' => 'automatic', 'scope' => 'order', 'discount_type' => 'fixed_amount', 'discount_value' => 5, 'min_subtotal_amount' => 100, 'max_subtotal_amount' => 50], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('max_subtotal_amount');
    $this->tenantJson('POST', '/api/admin/promotions', ['name' => 'X', 'trigger' => 'automatic', 'scope' => 'line', 'discount_type' => 'percentage', 'discount_value' => 5, 'valid_time_from' => '09:00'], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('valid_time_to');
    $this->tenantJson('POST', '/api/admin/promotions', ['name' => 'X', 'trigger' => 'automatic', 'scope' => 'line', 'discount_type' => 'percentage', 'discount_value' => 5, 'applies_to_online' => false, 'applies_to_pos' => false], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('applies_to_online');
    $this->tenantJson('POST', '/api/admin/promotions', ['name' => 'X', 'trigger' => 'automatic', 'scope' => 'line', 'discount_type' => 'percentage', 'discount_value' => 5, 'targets' => [['target_type' => 'category', 'target_id' => 999999]]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('targets.0.target_id');

    $promotion = $this->tenantJson('POST', '/api/admin/promotions', [
        'name' => 'Weekend shoes', 'trigger' => 'coupon', 'scope' => 'line', 'discount_type' => 'percentage', 'discount_value' => 15,
        'valid_days' => [6, 7], 'valid_time_from' => '09:00', 'valid_time_to' => '17:00',
        'targets' => [['target_type' => 'category', 'target_id' => $this->apparel->id], ['target_type' => 'product', 'target_id' => $this->mug->id, 'mode' => 'exclude']],
    ], $this->auth)->assertCreated()->assertJsonPath('data.valid_days', [6, 7])->assertJsonPath('data.valid_time_from', '09:00')
        ->assertJsonCount(2, 'data.targets')->json('data');

    tenancy()->initialize($this->tenant);
    expect(Promotion::query()->find($promotion['id'])->valid_days_of_week)->toBe(96);

    $copy = $this->tenantJson('POST', "/api/admin/promotions/{$promotion['id']}/duplicate", [], $this->auth)->assertCreated()
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.name', 'Weekend shoes (copy)')->json('data');
    expect($copy['targets'])->toHaveCount(2);

    // Once redeemed, the rule is frozen and the limit cannot drop below use.
    tenancy()->initialize($this->tenant);
    DB::connection('tenant')->table('promotion_redemptions')->insert([
        'promotion_id' => $promotion['id'], 'order_id' => promoOrderId(), 'promotion_name_snapshot' => 'x', 'scope_snapshot' => 'line',
        'discount_type_snapshot' => 'percentage', 'discount_amount' => 1, 'base_discount_amount' => 1, 'status' => 'committed',
    ]);
    DB::connection('tenant')->table('promotions')->where('id', $promotion['id'])->update(['times_redeemed' => 3]);

    $this->tenantJson('PATCH', "/api/admin/promotions/{$promotion['id']}", ['discount_value' => 20], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'promotion_in_use');
    $this->tenantJson('PATCH', "/api/admin/promotions/{$promotion['id']}", ['usage_limit_total' => 2], $this->auth)->assertStatus(422)->assertJsonValidationErrors('usage_limit_total');
    $this->tenantJson('PATCH', "/api/admin/promotions/{$promotion['id']}", ['name' => 'Renamed', 'usage_limit_total' => 10], $this->auth)->assertOk();
    $this->tenantJson('GET', "/api/admin/promotions/{$promotion['id']}", [], $this->auth)->assertOk()
        ->assertJsonPath('data.usage.by_status.committed', 1)->assertJsonPath('data.usage.orders', 1);
    $this->tenantJson('GET', "/api/admin/promotions/{$promotion['id']}/redemptions", [], $this->auth)->assertOk()->assertJsonCount(1, 'data');

    $this->tenantJson('DELETE', "/api/admin/promotions/{$copy['id']}", [], $this->auth)->assertOk();
    $this->tenantJson('GET', '/api/admin/promotions?trigger=coupon', [], $this->auth)->assertOk()->assertJsonCount(1, 'data');
});

it('creates, generates and manages coupon codes', function (): void {
    $automatic = promo([])->id;
    $promotion = promo(['trigger' => 'coupon', 'scope' => 'order'])->id;

    $this->tenantJson('POST', "/api/admin/promotions/{$automatic}/coupons", ['code' => 'NOPE'], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'promotion_not_coupon');
    $id = $this->tenantJson('POST', "/api/admin/promotions/{$promotion}/coupons", ['code' => ' save-20 ', 'usage_limit' => 1], $this->auth)
        ->assertCreated()->assertJsonPath('data.code', 'SAVE-20')->json('data.id');
    $this->tenantJson('POST', "/api/admin/promotions/{$promotion}/coupons", ['code' => 'Save-20'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('code');
    $this->tenantJson('POST', "/api/admin/promotions/{$promotion}/coupons", ['code' => 'NO SPACES'], $this->auth)->assertStatus(422);

    $this->tenantJson('POST', "/api/admin/promotions/{$promotion}/coupons/generate", ['count' => 25, 'prefix' => 'vip-', 'length' => 8], $this->auth)
        ->assertCreated()->assertJsonPath('data.count', 25)->assertJsonPath('data.queued', false);

    tenancy()->initialize($this->tenant);
    $codes = Coupon::query()->where('code', 'like', 'VIP-%')->pluck('code');
    expect($codes)->toHaveCount(25)->and($codes->every(static fn (string $c): bool => (bool) preg_match('/^VIP-[A-HJ-NP-Z2-9]{8}$/', $c)))->toBeTrue();

    Bus::fake([GenerateCouponCodes::class]);
    $this->tenantJson('POST', "/api/admin/promotions/{$promotion}/coupons/generate", ['count' => 5000], $this->auth)->assertStatus(202)->assertJsonPath('data.queued', true);
    Bus::assertDispatched(GenerateCouponCodes::class, static fn (GenerateCouponCodes $job): bool => $job->count === 5000 && $job->length === 10);

    $this->tenantJson('POST', '/api/admin/coupons/bulk', ['action' => 'deactivate', 'ids' => [$id, 999999]], $this->auth)->assertOk()
        ->assertJsonPath('data.results.0.status', 'ok')->assertJsonPath('data.results.1.status', 'error');
    $this->tenantJson('GET', "/api/admin/promotions/{$promotion}/coupons?is_active=0", [], $this->auth)->assertOk()->assertJsonCount(1, 'data');

    tenancy()->initialize($this->tenant);
    DB::connection('tenant')->table('promotion_redemptions')->insert([
        'promotion_id' => $promotion, 'coupon_id' => $id, 'order_id' => promoOrderId(), 'promotion_name_snapshot' => 'x', 'scope_snapshot' => 'order',
        'discount_type_snapshot' => 'percentage', 'discount_amount' => 1, 'base_discount_amount' => 1, 'status' => 'reserved',
    ]);
    $this->tenantJson('DELETE', "/api/admin/coupons/{$id}", [], $this->auth)->assertStatus(409)->assertJsonPath('meta.error_code', 'coupon_redeemed');
});

it('applies one line promotion per line, one order promotion and allocates by largest remainder', function (): void {
    $engine = app(PromotionEngine::class);
    promo(['name' => 'Apparel 10%', 'targets' => [['target_type' => 'category', 'target_id' => $this->apparel->id]]]);
    $five = promo(['name' => '5 off 50', 'scope' => 'order', 'discount_type' => 'fixed_amount', 'discount_value' => 5, 'min_subtotal_amount' => 50]);

    $result = $engine->evaluate(basket());
    expect($result->lines[0]['discount_amount'])->toBe('11.5300')
        ->and($result->lines[1]['discount_amount'])->toBe('1.4700')
        ->and($result->orderDiscountAmount)->toBe('5.0000')
        ->and($result->discountAmount())->toBe('13.0000')
        ->and(array_column($result->applied, 'label'))->toBe(['Apparel 10%', '5 off 50']);

    // A bigger order coupon takes the single order slot (20% of 102, capped at 15).
    $big = promo(['name' => 'Big', 'trigger' => 'coupon', 'scope' => 'order', 'discount_value' => 20, 'max_discount_amount' => 15]);
    app(CouponService::class)->createCoupon($big, ['code' => 'BIGGY']);
    $withCoupon = $engine->evaluate(basket(['couponCode' => 'biggy']));
    expect($withCoupon->couponApplied())->toBeTrue()
        ->and($withCoupon->orderDiscountAmount)->toBe('15.0000')
        ->and(array_column($withCoupon->applied, 'promotion_id'))->not->toContain($five->id);

    // A higher-priority automatic order promotion keeps the single order slot.
    app(PromotionService::class)->updatePromotion($five, ['priority' => 5]);
    $outranked = $engine->evaluate(basket(['couponCode' => 'BIGGY']));
    expect($outranked->couponApplied())->toBeFalse()
        ->and($outranked->couponRejectionReason)->toBe('not_combinable')
        ->and($outranked->orderDiscountAmount)->toBe('5.0000');

    // An exclusive promotion stands alone.
    promo(['name' => 'Mug half', 'discount_value' => 50, 'is_exclusive' => true, 'priority' => 10, 'targets' => [['target_type' => 'product', 'target_id' => $this->mug->id]]]);
    $exclusive = $engine->evaluate(basket(['couponCode' => 'BIGGY']));
    expect(array_column($exclusive->applied, 'label'))->toBe(['Mug half'])
        ->and($exclusive->lines[1]['discount_amount'])->toBe('15.0000')
        ->and($exclusive->lines[0]['discount_amount'])->toBe('0.0000')
        ->and($exclusive->couponRejectionReason)->toBe('not_combinable');
});

it('reports why a coupon does not apply', function (): void {
    $engine = app(PromotionEngine::class);
    $coupons = app(CouponService::class);
    $customer = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);

    $order = promo(['trigger' => 'coupon', 'scope' => 'order']);
    $coupons->createCoupon($order, ['code' => 'OLDIE', 'expires_at' => now()->subDay()]);
    $coupons->createCoupon($order, ['code' => 'USED', 'usage_limit' => 1]);
    DB::connection('tenant')->table('coupons')->where('code', 'USED')->update(['times_redeemed' => 1]);
    $coupons->createCoupon($order, ['code' => 'MINE', 'assigned_customer_id' => $customer->id]);
    $coupons->createCoupon(promo(['trigger' => 'coupon', 'scope' => 'order', 'min_subtotal_amount' => 500]), ['code' => 'MIN500']);
    $coupons->createCoupon(promo(['trigger' => 'coupon', 'scope' => 'order', 'first_order_only' => true]), ['code' => 'FIRST']);
    $coupons->createCoupon(promo(['trigger' => 'coupon', 'scope' => 'order', 'starts_at' => now()->addDay()]), ['code' => 'SOON']);
    $coupons->createCoupon(promo(['trigger' => 'coupon', 'scope' => 'line', 'applies_to_online' => false]), ['code' => 'POSONLY']);
    $coupons->createCoupon(promo(['trigger' => 'coupon', 'scope' => 'line', 'targets' => [['target_type' => 'category', 'target_id' => $this->shoes->id]]]), ['code' => 'SHOES']);
    $limited = promo(['trigger' => 'coupon', 'scope' => 'order', 'usage_limit_per_customer' => 1]);
    $coupons->createCoupon($limited, ['code' => 'ONCE']);
    DB::connection('tenant')->table('promotion_redemptions')->insert([
        'promotion_id' => $limited->id, 'order_id' => promoOrderId(), 'customer_id' => $customer->id, 'promotion_name_snapshot' => 'x', 'scope_snapshot' => 'order',
        'discount_type_snapshot' => 'percentage', 'discount_amount' => 1, 'base_discount_amount' => 1, 'status' => 'committed',
    ]);

    $reason = fn (string $code, array $ctx = []): ?string => $engine->evaluate(basket(['couponCode' => $code, ...$ctx]))->couponRejectionReason;

    expect($reason('NOPE'))->toBe('not_found')
        ->and($reason('OLDIE'))->toBe('expired')
        ->and($reason('USED'))->toBe('usage_limit_reached')
        ->and($reason('MINE'))->toBe('customer_not_eligible')
        ->and($reason('MINE', ['customerId' => $customer->id]))->toBeNull()
        ->and($reason('MIN500'))->toBe('minimum_not_met')
        ->and($reason('FIRST'))->toBe('first_order_only')
        ->and($reason('FIRST', ['isFirstOrder' => true]))->toBeNull()
        ->and($reason('SOON'))->toBe('not_started')
        ->and($reason('POSONLY'))->toBe('channel_not_allowed')
        ->and($reason('POSONLY', ['channel' => 'pos']))->toBeNull()
        ->and($reason('SHOES', ['lines' => [new PricingLine(0, $this->mug, null, '1', '10.0000')]]))->toBe('no_eligible_items')
        ->and($reason('ONCE', ['customerId' => $customer->id]))->toBe('customer_limit_reached')
        ->and($reason('ONCE', ['email' => 'guest@shop.test']))->toBeNull();

    // Free shipping, capped.
    $coupons->createCoupon(promo(['trigger' => 'coupon', 'scope' => 'shipping', 'discount_type' => 'free_shipping', 'max_discount_amount' => 1000]), ['code' => 'SHIPFREE']);
    $shipping = $engine->evaluate(basket(['couponCode' => 'SHIPFREE', 'shippingAmount' => '1500.0000']));
    expect($shipping->shippingDiscountAmount)->toBe('1000.0000')->and($shipping->couponApplied())->toBeTrue();
});

it('honours day and time windows, buyer groups and sale-priced lines', function (): void {
    $engine = app(PromotionEngine::class);

    // Monday 22:00 to 02:00, in UTC (the store's default timezone).
    promo(['name' => 'Late', 'valid_days' => [1], 'valid_time_from' => '22:00', 'valid_time_to' => '02:00']);
    $applies = fn (string $at): bool => $engine->evaluate(basket(['at' => Carbon::parse($at, 'UTC')]))->applied !== [];

    expect($applies('2026-10-05 23:00:00'))->toBeTrue()   // Monday night
        ->and($applies('2026-10-06 01:30:00'))->toBeTrue()  // after midnight: the window started Monday
        ->and($applies('2026-10-06 23:00:00'))->toBeFalse() // Tuesday night
        ->and($applies('2026-10-05 12:00:00'))->toBeFalse();

    DB::connection('tenant')->table('promotions')->delete();
    $vip = CustomerGroup::query()->create(['name' => 'VIP']);
    promo(['name' => 'VIP', 'targets' => [['target_type' => 'customer_group', 'target_id' => $vip->id]]]);

    expect($engine->evaluate(basket())->applied)->toBe([])
        ->and($engine->evaluate(basket(['customerId' => 1, 'customerGroupId' => $vip->id]))->applied)->toHaveCount(1);

    // A flash-sale line is not eligible unless the promotion allows sale items.
    $sale = basket(['customerGroupId' => $vip->id, 'lines' => [new PricingLine(0, $this->mug, null, '1', '8.0000', 'flash_sale')]]);
    expect($engine->evaluate($sale)->applied)->toBe([]);
});

it('lowers storefront prices during a flash sale and previews automatic promotions', function (): void {
    $sale = $this->tenantJson('POST', '/api/admin/flash-sales', ['name' => 'Payday', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String()], $this->auth)
        ->assertCreated()->json('data.id');

    $this->tenantJson('POST', "/api/admin/flash-sales/{$sale}/products", ['product_id' => $this->shoe->id, 'sale_price' => '45'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('sale_price');
    $this->tenantJson('POST', "/api/admin/flash-sales/{$sale}/products", ['product_id' => $this->shoe->id, 'sale_price' => '5', 'quantity_limit' => 2], $this->auth)->assertCreated();
    $this->tenantJson('POST', "/api/admin/flash-sales/{$sale}/products", ['product_id' => $this->shoe->id, 'sale_price' => '6'], $this->auth)->assertStatus(409);

    $other = $this->tenantJson('POST', '/api/admin/flash-sales', ['name' => 'Overlap', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String()], $this->auth)->json('data.id');
    $this->tenantJson('POST', "/api/admin/flash-sales/{$other}/products", ['product_id' => $this->shoe->id, 'sale_price' => '4'], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'flash_sale_overlap');

    $this->tenantJson('GET', '/api/products/runner')->assertOk()->assertJsonPath('data.price', '5.0000')->assertJsonPath('data.compare_at_price', null);
    expect(array_column($this->tenantJson('GET', '/api/products?sort=price_asc')->assertOk()->json('data'), 'name'))->toBe(['Runner', 'Mug']);
    $this->tenantJson('GET', '/api/flash-sales')->assertOk()->assertJsonPath('data.0.products.0.sale_price', '5.0000')->assertJsonPath('data.0.products.0.quantity_remaining', '2.000');

    // Sold out: the regular price returns.
    tenancy()->initialize($this->tenant);
    DB::connection('tenant')->table('flash_sale_products')->update(['quantity_claimed' => 2]);
    app(FlashSalePrices::class)->flush();
    $this->tenantJson('GET', '/api/products/runner')->assertOk()->assertJsonPath('data.price', '40.0000');
    $this->tenantJson('DELETE', "/api/admin/flash-sales/{$sale}", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'flash_sale_claimed');

    // Automatic line promotions preview on cards.
    $this->tenantJson('POST', '/api/admin/promotions', ['name' => 'Mug deal', 'public_label' => '20% off mugs', 'trigger' => 'automatic', 'scope' => 'line', 'discount_type' => 'percentage', 'discount_value' => 20,
        'targets' => [['target_type' => 'product', 'target_id' => $this->mug->id]]], $this->auth)->assertCreated();
    $this->tenantJson('GET', '/api/products/mug')->assertOk()->assertJsonPath('data.promotion.label', '20% off mugs')->assertJsonPath('data.promotion.price', '8.0000');
    $this->tenantJson('GET', '/api/products/runner')->assertOk()->assertJsonPath('data.promotion', null);
});
