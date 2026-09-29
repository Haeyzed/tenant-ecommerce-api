<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Currency\Support\ExchangeRateProvider;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Shipping\Services\ShippingMethodService;
use App\Modules\Shipping\Services\ShippingZoneService;
use App\Modules\Users\Models\User;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'standard');

    $landlord = DB::connection('landlord');
    $landlord->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    foreach (['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NGN' => '₦'] as $code => $symbol) {
        $landlord->table('currencies')->insert(['country_id' => 1, 'name' => $code, 'code' => $code, 'symbol' => $symbol, 'symbol_native' => $symbol]);
    }

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40000', 'is_active' => true]);
    $this->bag = Product::query()->create(['name' => 'Tote', 'price' => '5000', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($main, $this->shoe, null, '5', 'adjustment_in');
    app(InventoryService::class)->adjustStock($main, $this->bag, null, '5', 'adjustment_in');

    $zone = app(ShippingZoneService::class)->createZone(['name' => 'Nigeria', 'regions' => [['country_id' => 1]]]);
    $this->method = app(ShippingMethodService::class)->createMethod(['shipping_zone_id' => $zone->id, 'name' => 'GIG', 'fulfillment_type' => 'courier', 'cost' => '2000']);

    $this->tenantJson('POST', '/api/admin/modules/multi_currency/enable', [], $this->auth)->assertOk();
});

it('sells in a second currency: explicit prices win, others are estimated, and the order keeps its rate', function (): void {
    // The base row exists without being created; USD is added with a manual rate (1 NGN = 0.00065 USD).
    $this->tenantJson('GET', '/api/admin/currencies', [], $this->auth)->assertOk()
        ->assertJsonPath('data.base_currency', 'NGN')
        ->assertJsonPath('data.currencies.0.currency_code', 'NGN')
        ->assertJsonPath('data.currencies.0.is_base', true);

    $usd = $this->tenantJson('POST', '/api/admin/currencies', ['currency_code' => 'usd', 'display_symbol' => '$', 'rate' => '0.00065'], $this->auth)
        ->assertCreated()->assertJsonPath('data.is_offered', true)->assertJsonPath('data.rate_source', 'manual')->json('data');

    $this->tenantJson('POST', "/api/admin/products/{$this->shoe->id}/prices", ['currency_code' => 'USD', 'price' => '30', 'compare_at_price' => '35'], $this->auth)
        ->assertCreated()->assertJsonPath('data.price', '30.0000');
    $this->tenantJson('POST', "/api/admin/products/{$this->shoe->id}/prices", ['currency_code' => 'NGN', 'price' => '1'], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'currency_not_supported');
    $this->tenantJson('POST', "/api/admin/products/{$this->shoe->id}/prices", ['currency_code' => 'USD', 'price' => '31'], $this->auth)
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'price_exists');

    // The storefront shows the explicit price as is and converts the rest.
    $cards = collect($this->tenantJson('GET', '/api/products?currency=USD')->assertOk()->json('data'))->keyBy('name');
    expect($cards['Runner'])->toMatchArray(['price' => '30.0000', 'compare_at_price' => '35.0000', 'currency_code' => 'USD', 'is_estimated' => false])
        ->and($cards['Tote'])->toMatchArray(['price' => '3.2500', 'currency_code' => 'USD', 'is_estimated' => true]);
    $this->tenantJson('GET', '/api/products?currency=EUR')->assertStatus(422)->assertJsonPath('meta.error_code', 'currency_not_supported');
    $this->tenantJson('GET', '/api/storefront/config')->assertOk()->assertJsonPath('data.formatting.currencies.1.currency_code', 'USD');

    // The cart switches currency and is quoted in it, shipping converted.
    $token = $this->tenantJson('POST', '/api/cart/items', ['product_id' => $this->shoe->id, 'quantity' => 1])->assertCreated()->json('data.guest_token');
    $this->tenantJson('POST', '/api/cart/items', ['product_id' => $this->bag->id, 'quantity' => 2], ['X-Guest-Token' => $token])->assertCreated();
    $this->tenantJson('PATCH', '/api/cart/currency', ['currency_code' => 'EUR'], ['X-Guest-Token' => $token])->assertStatus(422)->assertJsonPath('meta.error_code', 'currency_not_supported');
    $this->tenantJson('PATCH', '/api/cart/currency', ['currency_code' => 'USD'], ['X-Guest-Token' => $token])->assertOk();

    $quote = $this->tenantJson('GET', '/api/cart?country_id=1&shipping_method_id='.$this->method->id, [], ['X-Guest-Token' => $token])->assertOk()->json('data.quote');
    expect($quote['currency_code'])->toBe('USD')
        ->and(collect($quote['lines'])->pluck('unit_price', 'product.name')->all())->toBe(['Runner' => '30.0000', 'Tote' => '3.2500'])
        ->and(collect($quote['lines'])->pluck('is_estimated', 'product.name')->all())->toBe(['Runner' => false, 'Tote' => true])
        ->and($quote['subtotal'])->toBe('36.5000')
        ->and($quote['shipping_amount'])->toBe('1.3000');

    $order = $this->tenantJson('POST', '/api/orders', [
        'quote_hash' => $quote['quote_hash'],
        'guest_email' => 'ada@shop.test',
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
        'shipping_method_id' => $this->method->id,
    ], ['X-Guest-Token' => $token, 'Idempotency-Key' => Str::uuid()->toString()])->assertCreated()->json('data');

    tenancy()->initialize($this->tenant);
    $row = Order::query()->findOrFail($order['id']);
    // 1 USD = 1/0.00065 NGN, captured once; the base amount uses it.
    expect($row->currency_code)->toBe('USD')
        ->and((string) $row->exchange_rate_used)->toBe('1538.461538461538')
        ->and((string) $row->base_currency_amount)->toBe(Money::round(bcmul((string) $row->total, '1538.461538461538', 12), 'NGN'));

    // A retired currency falls back to the base on the next quote.
    $this->tenantJson('PATCH', "/api/admin/currencies/{$usd['id']}/deactivate", [], $this->auth)->assertOk()->assertJsonPath('data.is_offered', false);
    $token2 = $this->tenantJson('POST', '/api/cart/items', ['product_id' => $this->bag->id, 'quantity' => 1])->assertCreated()->json('data.guest_token');
    $this->tenantJson('GET', '/api/cart', [], ['X-Guest-Token' => $token2])->assertOk()->assertJsonPath('data.quote.currency_code', 'NGN');

    // A-31: the base currency is locked once an order exists.
    $this->tenantJson('POST', '/api/admin/currencies', ['currency_code' => 'USD'], $this->auth)->assertCreated();
    $this->tenantJson('POST', "/api/admin/currencies/{$usd['id']}/set-base", [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'base_currency_locked');
});

it('changes the base currency only before the first order, and refreshes rates without touching manual ones', function (): void {
    $gbp = $this->tenantJson('POST', '/api/admin/currencies', ['currency_code' => 'GBP', 'rate' => '0.0005'], $this->auth)->assertCreated()->json('data');
    $eur = $this->tenantJson('POST', '/api/admin/currencies', ['currency_code' => 'EUR'], $this->auth)->assertCreated()
        ->assertJsonPath('data.is_offered', false)->json('data'); // no rate yet

    config(['currency.fx_provider' => 'open_er_api']);
    Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'base_code' => 'NGN', 'rates' => ['EUR' => 0.00061, 'GBP' => 0.00052, 'USD' => 0.00066]])]);

    $this->tenantJson('POST', '/api/admin/currencies/refresh-rates', [], $this->auth)->assertOk()->assertJsonPath('data.stored', 1);

    tenancy()->initialize($this->tenant);
    $rates = ExchangeRate::query()->pluck('rate', 'target_currency_code')->map(fn ($r): string => (string) $r)->all();
    expect($rates)->toEqualCanonicalizing(['GBP' => '0.000500000000', 'EUR' => '0.000610000000'])
        ->and(app(CurrencyService::class)->offered('EUR'))->toBeTrue();

    $this->tenantJson('POST', "/api/admin/currencies/{$eur['id']}/set-base", [], $this->auth)->assertOk()->assertJsonPath('data.is_base', true);

    tenancy()->initialize($this->tenant);
    expect(app(TenantSettingsService::class)->get('default_currency'))->toBe('EUR')
        ->and(ExchangeRate::query()->count())->toBe(0);
    $this->tenantJson('PATCH', "/api/admin/currencies/{$eur['id']}/deactivate", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'currency_is_base');
    expect($gbp['currency_code'])->toBe('GBP');
});

it('crosses Open Exchange Rates USD rates to the base, adds the store margin and shares one response', function (): void {
    $this->tenantJson('POST', '/api/admin/currencies', ['currency_code' => 'USD'], $this->auth)->assertCreated();
    $this->tenantJson('POST', '/api/admin/currencies', ['currency_code' => 'EUR'], $this->auth)->assertCreated();
    $this->tenantJson('PATCH', '/api/admin/settings', ['exchange_rate_margin_percent' => '2'], $this->auth)->assertOk();

    config(['currency.fx_provider' => 'openexchangerates', 'currency.providers.openexchangerates.app_id' => null]);
    Cache::store('landlord')->forget(ExchangeRateProvider::OXR_CACHE_KEY);
    Http::fake(['openexchangerates.org/*' => Http::response(['base' => 'USD', 'rates' => ['USD' => 1, 'NGN' => 1600, 'EUR' => 0.92]])]);

    // Without an app id the provider counts as not configured.
    $this->tenantJson('POST', '/api/admin/currencies/refresh-rates', [], $this->auth)->assertOk()->assertJsonPath('data.stored', 0);

    config(['currency.providers.openexchangerates.app_id' => 'test-app-id']);
    $this->tenantJson('POST', '/api/admin/currencies/refresh-rates', [], $this->auth)->assertOk()->assertJsonPath('data.stored', 2);
    $this->tenantJson('POST', '/api/admin/currencies/refresh-rates', [], $this->auth)->assertOk()->assertJsonPath('data.stored', 2);

    tenancy()->initialize($this->tenant);
    // 1 NGN = 1/1600 USD and 0.92/1600 EUR, each plus 2 %.
    $rates = ExchangeRate::query()->pluck('rate', 'target_currency_code')->map(fn ($r): string => (string) $r)->all();
    expect($rates)->toEqualCanonicalizing(['USD' => '0.000637500000', 'EUR' => '0.000586500000']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'latest.json') && $request['app_id'] === 'test-app-id');
});
