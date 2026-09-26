<?php

declare(strict_types=1);

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Services\PricingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehousePricingService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Promotions\Services\CouponService;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShippingMethodService;
use App\Modules\Shipping\Services\ShippingZoneService;
use App\Modules\Tax\Services\TaxService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);
    DB::connection('landlord')->table('states')->insert(['id' => 10, 'country_id' => 1, 'name' => 'Lagos', 'country_code' => 'NG', 'state_code' => 'LA']);

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();

    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'is_active' => true]);
    $this->mug = Product::query()->create(['name' => 'Mug', 'price' => '10', 'is_active' => true]);
    $this->ebook = Product::query()->create(['name' => 'Guide', 'price' => '5', 'product_type' => 'digital', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '10', 'adjustment_in');
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '5', 'adjustment_in');

    app(TaxService::class)->createTaxRate(['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => '7.5']);
    $zone = app(ShippingZoneService::class)->createZone(['name' => 'Nigeria', 'regions' => [['country_id' => 1]]]);
    $this->method = app(ShippingMethodService::class)->createMethod(['shipping_zone_id' => $zone->id, 'name' => 'GIG', 'fulfillment_type' => 'courier', 'cost' => '1500']);
});

function addToCart(int $productId, mixed $quantity = 1, ?string $token = null, array $headers = []): TestResponse
{
    return test()->tenantJson('POST', '/api/cart/items', ['product_id' => $productId, 'quantity' => $quantity], $token === null ? $headers : [...$headers, 'X-Guest-Token' => $token]);
}

it('gives guests a token-scoped cart and validates what goes in', function (): void {
    $this->tenantJson('GET', '/api/cart')->assertOk()->assertJsonPath('data.quote', null);
    tenancy()->initialize($this->tenant);
    expect(Cart::query()->count())->toBe(0);

    $response = addToCart($this->shoe->id, 2)->assertCreated()->assertJsonPath('data.items_count', 1)->assertJsonPath('data.quote.subtotal', '80.0000');
    $token = $response->json('data.guest_token');
    expect($token)->toMatch('/^[0-9a-f-]{36}$/')->and($response->headers->get('X-Guest-Token'))->toBe($token);

    addToCart($this->shoe->id, 1, $token)->assertCreated()->assertJsonPath('data.quote.lines.0.quantity', '3.000');
    addToCart($this->mug->id, '1.5', $token)->assertStatus(422)->assertJsonValidationErrors('quantity');
    addToCart($this->mug->id, 6, $token)->assertStatus(422)->assertJsonPath('meta.error_code', 'insufficient_stock')->assertJsonPath('meta.details.available', '5.000');
    addToCart($this->ebook->id, 1, $token)->assertCreated()->assertJsonPath('data.items_count', 2);

    // A made-up token never adopts someone's cart: it gets a fresh one.
    $other = addToCart($this->mug->id, 1, str_repeat('b', 36))->assertCreated()->json('data.guest_token');
    expect($other)->not->toBe($token)->not->toBe(str_repeat('b', 36));

    $item = $this->tenantJson('GET', '/api/cart', [], ['X-Guest-Token' => $token])->json('data.quote.lines.0.item_id');
    $this->tenantJson('PATCH', "/api/cart/items/{$item}", ['quantity' => 1], ['X-Guest-Token' => $other])->assertNotFound();
    $this->tenantJson('PATCH', "/api/cart/items/{$item}", ['quantity' => 1], ['X-Guest-Token' => $token])->assertOk()->assertJsonPath('data.quote.lines.0.quantity', '1.000');
    $this->tenantJson('PATCH', "/api/cart/items/{$item}", ['quantity' => 0], ['X-Guest-Token' => $token])->assertOk()->assertJsonPath('data.items_count', 1);
});

it('quotes tax, shipping and a coupon, with a stable quote hash', function (): void {
    $token = addToCart($this->shoe->id, 2)->json('data.guest_token');
    addToCart($this->mug->id, 3, $token)->assertCreated();
    $guest = ['X-Guest-Token' => $token];

    // Without an address, tax and shipping are pending.
    $this->tenantJson('GET', '/api/cart', [], $guest)->assertOk()
        ->assertJsonPath('data.quote.subtotal', '110.0000')->assertJsonPath('data.quote.tax_amount', null)->assertJsonPath('data.quote.shipping_amount', null);

    $this->tenantJson('GET', '/api/cart?country_id=1&state_id=10', [], $guest)->assertOk()
        ->assertJsonPath('data.quote.issues.0.code', 'shipping_method_required');
    $this->tenantJson('GET', '/api/cart?country_id=1&shipping_method_id=999999', [], $guest)->assertOk()
        ->assertJsonPath('data.quote.issues.0.code', 'shipping_method_unavailable');

    $url = "/api/cart?country_id=1&state_id=10&shipping_method_id={$this->method->id}";
    $quote = $this->tenantJson('GET', $url, [], $guest)->assertOk()->json('data.quote');
    expect($quote['tax_amount'])->toBe('8.2500')
        ->and($quote['lines'][0]['tax_amount'])->toBe('6.0000')
        ->and($quote['lines'][0]['line_total'])->toBe('86.0000')
        ->and($quote['shipping_amount'])->toBe('1500.0000')
        ->and($quote['total'])->toBe('1618.2500')
        ->and($quote['issues'])->toBe([])
        ->and($this->tenantJson('GET', $url, [], $guest)->json('data.quote.quote_hash'))->toBe($quote['quote_hash']);

    tenancy()->initialize($this->tenant);
    $promotion = app(PromotionService::class)->createPromotion(['name' => '10% off', 'trigger' => 'coupon', 'scope' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10]);
    app(CouponService::class)->createCoupon($promotion, ['code' => 'SAVE10']);
    $big = app(PromotionService::class)->createPromotion(['name' => 'Big spenders', 'trigger' => 'coupon', 'scope' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10, 'min_subtotal_amount' => 500]);
    app(CouponService::class)->createCoupon($big, ['code' => 'BIG500']);

    $this->tenantJson('POST', '/api/cart/apply-coupon', ['code' => 'NOPE'], $guest)->assertStatus(422)->assertJsonPath('meta.details.reason', 'not_found');
    $this->tenantJson('POST', '/api/cart/apply-coupon', ['code' => 'big500'], $guest)->assertStatus(422)->assertJsonPath('meta.details.reason', 'minimum_not_met');
    $this->tenantJson('POST', '/api/cart/apply-coupon', ['code' => 'save10'], $guest)->assertOk()
        ->assertJsonPath('data.quote.coupon.code', 'SAVE10')->assertJsonPath('data.quote.coupon.applied', true)->assertJsonPath('data.quote.discount_amount', '11.0000');

    // 99.00 × 7.5% = 7.425 → 7.43 across lines: 72 × 7.5% = 5.40, 27 × 7.5% = 2.025 → 2.03.
    $discounted = $this->tenantJson('GET', $url, [], $guest)->json('data.quote');
    expect($discounted['tax_amount'])->toBe('7.4300')
        ->and($discounted['total'])->toBe('1606.4300')
        ->and($discounted['quote_hash'])->not->toBe($quote['quote_hash']);

    // A coupon that stops applying stays on the cart with its reason.
    $item = $discounted['lines'][1]['item_id'];
    $this->tenantJson('DELETE', "/api/cart/items/{$discounted['lines'][0]['item_id']}", [], $guest)->assertOk();
    $this->tenantJson('PATCH', "/api/cart/items/{$item}", ['quantity' => 1], $guest)->assertOk()->assertJsonPath('data.quote.coupon.applied', true);
    $this->tenantJson('DELETE', '/api/cart/coupon', [], $guest)->assertOk()->assertJsonPath('data.quote.coupon', null);

    // A digital-only basket needs no shipping.
    $digital = addToCart($this->ebook->id)->json('data.guest_token');
    $this->tenantJson('GET', '/api/cart?country_id=1', [], ['X-Guest-Token' => $digital])->assertOk()
        ->assertJsonPath('data.quote.requires_shipping', false)->assertJsonPath('data.quote.shipping_amount', '0.0000')->assertJsonPath('data.quote.issues', []);
});

it('merges the guest cart into the customer cart on registration and login', function (): void {
    $token = addToCart($this->mug->id, 2)->json('data.guest_token');

    $customer = $this->tenantJson('POST', '/api/auth/register', ['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'], ['X-Guest-Token' => $token])
        ->assertCreated()->json('data.token');
    $auth = ['Authorization' => 'Bearer '.$customer];
    $this->tenantJson('GET', '/api/cart', [], $auth)->assertOk()->assertJsonPath('data.guest_token', null)->assertJsonPath('data.quote.lines.0.quantity', '2.000');

    // A second guest session merges by summing quantities.
    $second = addToCart($this->mug->id, 2)->json('data.guest_token');
    addToCart($this->shoe->id, 1, $second)->assertCreated();
    $this->tenantJson('POST', '/api/auth/login', ['email' => 'ada@shop.test', 'password' => 'Secret123'], ['X-Guest-Token' => $second])->assertOk();

    $lines = $this->tenantJson('GET', '/api/cart', [], $auth)->json('data.quote.lines');
    expect(array_column($lines, 'quantity'))->toBe(['4.000', '1.000']);

    tenancy()->initialize($this->tenant);
    expect(Cart::query()->count())->toBe(1);
});

it('reports lines that can no longer be bought and leaves them out of the totals', function (): void {
    $token = addToCart($this->shoe->id, 2)->json('data.guest_token');
    addToCart($this->mug->id, 5, $token)->assertCreated();

    tenancy()->initialize($this->tenant);
    $this->shoe->forceFill(['is_active' => false])->save();
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '-3', 'adjustment_out');

    $quote = $this->tenantJson('GET', '/api/cart', [], ['X-Guest-Token' => $token])->assertOk()->json('data.quote');
    expect(array_column($quote['lines'], 'status'))->toBe(['unavailable', 'out_of_stock'])
        ->and(array_column($quote['issues'], 'code'))->toBe(['item_unavailable', 'stock_conflict'])
        ->and($quote['subtotal'])->toBe('0.0000');
});

it('resolves unit prices through warehouse prices and flash sales, lowest last', function (): void {
    $pricing = app(PricingService::class);
    $this->shoe->forceFill(['has_warehouse_pricing' => true, 'compare_at_price' => '50'])->save();
    app(WarehousePricingService::class)->setWarehousePrice($this->shoe, $this->main, '38');

    $base = $pricing->resolveUnitPrice($this->shoe, null, null, 'NGN');
    $warehouse = $pricing->resolveUnitPrice($this->shoe, null, $this->main, 'ngn');
    expect([$base->unitPrice, $base->source, $base->compareAtPrice])->toBe(['40.0000', 'base', '50.0000'])
        ->and([$warehouse->unitPrice, $warehouse->source, $warehouse->compareAtPrice])->toBe(['38.0000', 'warehouse', null]);

    $sale = app(FlashSaleService::class)->createFlashSale(['name' => 'Now', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    app(FlashSaleService::class)->addProduct($sale, $this->shoe, '30');
    $flash = $pricing->resolveUnitPrice($this->shoe, null, $this->main, 'NGN');
    expect([$flash->unitPrice, $flash->source, $flash->compareAtPrice])->toBe(['30.0000', 'flash_sale', '38.0000']);

    expect(fn () => $pricing->resolveUnitPrice($this->shoe, null, null, 'USD'))->toThrow(ApiException::class);

    $token = addToCart($this->shoe->id)->json('data.guest_token');
    $this->tenantJson('GET', '/api/cart', [], ['X-Guest-Token' => $token])->assertOk()
        ->assertJsonPath('data.quote.lines.0.unit_price', '30.0000')->assertJsonPath('data.quote.lines.0.price_source', 'flash_sale');
});
