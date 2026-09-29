<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Modules\Integrations\WooCommerce\Services\WooCommerceSettingsService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Models\TaxRate;
use App\Modules\Users\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    // The fake store has no DNS; the guard's address checks are covered in PublicUrlGuardTest.
    config(['integrations.allow_private_hosts' => true]);
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(TenantSettingsService::class)->set('payment_mode', 'live');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];
    app(WarehouseService::class)->ensureDefault();
    $this->shop = Warehouse::query()->firstOrFail();

    $this->tenantJson('POST', '/api/admin/modules/woocommerce/enable', [], $this->staff)->assertOk();
    $this->wooRejects = false;
    fakeWooStore();
});

/**
 * A WooCommerce store behind the REST API; $this->woo holds its data.
 */
function fakeWooStore(): void
{
    $line = static fn (int $product, int $variation, string $name, int $qty, string $subtotal, string $total, string $tax): array => [
        'product_id' => $product, 'variation_id' => $variation, 'name' => $name, 'quantity' => $qty, 'subtotal' => $subtotal, 'total' => $total, 'total_tax' => $tax,
    ];
    $billing = ['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'Ada@Shop.test', 'phone' => '+2348000000001', 'address_1' => '1 Marina', 'city' => 'Lagos', 'country' => 'NG', 'postcode' => '100001'];

    test()->woo = [
        'products/categories' => [['id' => 10, 'name' => 'Shoes', 'slug' => 'shoes', 'parent' => 0], ['id' => 11, 'name' => 'Running &amp; Trail', 'slug' => 'running', 'parent' => 10]],
        'taxes' => [
            ['id' => 1, 'country' => 'NG', 'state' => '', 'rate' => '7.5000', 'name' => 'VAT', 'class' => 'standard'],
            ['id' => 2, 'country' => '', 'state' => '', 'rate' => '5.0000', 'name' => 'Everywhere', 'class' => 'standard'],
        ],
        'products' => [
            ['id' => 100, 'type' => 'simple', 'name' => 'Runner', 'sku' => 'RUN-1', 'regular_price' => '40000', 'status' => 'publish', 'manage_stock' => true, 'stock_quantity' => 5, 'categories' => [['id' => 11]], 'images' => []],
            ['id' => 200, 'type' => 'variable', 'name' => 'Tee', 'sku' => '', 'status' => 'publish', 'categories' => [], 'images' => []],
            ['id' => 300, 'type' => 'grouped', 'name' => 'Gift set', 'status' => 'publish', 'categories' => [], 'images' => []],
        ],
        'products/200/variations' => [
            ['id' => 201, 'sku' => 'TEE-S', 'regular_price' => '5000', 'manage_stock' => true, 'stock_quantity' => 3, 'attributes' => [['name' => 'Size', 'option' => 'S']]],
            ['id' => 202, 'sku' => 'TEE-M', 'regular_price' => '5500', 'manage_stock' => true, 'stock_quantity' => 0, 'attributes' => [['name' => 'Size', 'option' => 'M']]],
        ],
        'orders' => [
            // Paid: 2 × 40,000 + 7.5% VAT.
            ['id' => 500, 'status' => 'processing', 'currency' => 'NGN', 'date_paid' => '2026-09-29T10:00:00', 'billing' => $billing, 'shipping' => [],
                'line_items' => [$line(100, 0, 'Runner', 2, '80000.00', '80000.00', '6000.00')], 'shipping_total' => '0.00', 'shipping_tax' => '0.00', 'total' => '86000.00'],
            // Unpaid, with a 500 discount and 1,000 shipping.
            ['id' => 501, 'status' => 'pending', 'currency' => 'NGN', 'date_paid' => null, 'billing' => [...$billing, 'email' => 'bola@guest.test'], 'shipping' => [],
                'line_items' => [$line(200, 201, 'Tee - S', 1, '5000.00', '4500.00', '0.00')], 'shipping_total' => '1000.00', 'shipping_tax' => '0.00', 'total' => '5500.00'],
            ['id' => 502, 'status' => 'processing', 'currency' => 'USD', 'date_paid' => '2026-09-29T10:00:00', 'billing' => $billing, 'shipping' => [],
                'line_items' => [$line(100, 0, 'Runner', 1, '30.00', '30.00', '0.00')], 'shipping_total' => '0.00', 'shipping_tax' => '0.00', 'total' => '30.00'],
        ],
    ];

    Http::fake(function (Request $request) {
        $path = (string) preg_replace('#^/wp-json/wc/v3/#', '', (string) parse_url($request->url(), PHP_URL_PATH));

        if (! str_starts_with($request->url(), 'https://shop.example.com/wp-json/wc/v3/')) {
            return Http::response([], 404);
        }

        if (test()->wooRejects) {
            return Http::response(['code' => 'woocommerce_rest_cannot_view', 'message' => 'Sorry, you cannot list resources.'], 401);
        }

        if ($request->method() === 'GET') {
            return Http::response(test()->woo[$path] ?? [], 200, ['X-WP-TotalPages' => '1']);
        }

        return match (true) {
            $path === 'products' => Http::response(['id' => 900], 201),
            str_starts_with($path, 'products/900/variations') => Http::response(['id' => 901], 201),
            str_starts_with($path, 'products/') => Http::response(['id' => (int) explode('/', $path)[1]], 200),
            default => Http::response(['update' => []], 200),
        };
    });
}

function wooStockOf(Product $product, ?ProductVariant $variant = null): array
{
    tenancy()->initialize(test()->tenant);
    $row = Inventory::query()->where('product_id', $product->id)->where('product_variant_id', $variant?->id)->firstOrFail();

    return [(string) $row->quantity, (string) $row->reserved_quantity];
}

it('connects, imports the catalogue and orders once, pushes stock and keeps credentials write-only', function (): void {
    $settings = fn (array $body) => $this->tenantJson('PUT', '/api/admin/woocommerce/settings', $body, $this->staff);
    $keys = ['consumer_key' => 'ck_'.str_repeat('a1', 15), 'consumer_secret' => 'cs_'.str_repeat('b2', 15)];

    $settings(['store_url' => 'http://shop.example.com', ...$keys])->assertStatus(422)->assertJsonPath('meta.error_code', 'url_invalid');
    $settings(['store_url' => 'https://shop.example.com', ...$keys, 'is_active' => true])->assertStatus(422)->assertJsonPath('meta.error_code', 'import_warehouse_required');

    // Switching on tests the connection and runs the first sync (queues run inline in tests).
    $body = $settings(['is_active' => true, 'import_warehouse_id' => $this->shop->id])->assertOk()
        ->assertJsonPath('data.is_active', true)->assertJsonPath('data.has_consumer_secret', true)->json('data');
    expect($body)->not->toHaveKeys(['consumer_key', 'consumer_secret']);

    tenancy()->initialize($this->tenant);
    $running = Category::query()->where('slug', 'running')->firstOrFail();
    expect($running->name)->toBe('Running & Trail')->and($running->parent_id)->toBe(Category::query()->where('slug', 'shoes')->value('id'))
        ->and(TaxRate::query()->where('country_id', 1)->where('rate_percentage', '7.5000')->count())->toBe(1);

    $runner = Product::query()->where('sku', 'RUN-1')->firstOrFail();
    $tee = Product::query()->where('name', 'Tee')->firstOrFail();
    $small = ProductVariant::query()->where('sku', 'TEE-S')->firstOrFail();
    expect($runner->categories()->pluck('categories.id')->all())->toBe([$running->id])
        ->and($tee->product_type)->toBe('variable')->and((string) $tee->price)->toBe('5000.0000')
        // 5 imported, 2 sold in the paid WooCommerce order; 1 small tee reserved by the unpaid one.
        ->and(wooStockOf($runner))->toBe(['3.000', '0.000'])->and(wooStockOf($tee, $small))->toBe(['3.000', '1.000']);

    tenancy()->initialize($this->tenant);
    $paid = Order::query()->where('idempotency_key', 'woo:500')->firstOrFail();
    $unpaid = Order::query()->where('idempotency_key', 'woo:501')->firstOrFail();
    expect([$paid->order_source, $paid->payment_status, $paid->confirmed_at !== null, (string) $paid->total, $paid->customer_email])->toBe(['woocommerce', 'paid', true, '86000.0000', 'ada@shop.test'])
        ->and(OrderPayment::query()->where('order_id', $paid->id)->value('notes'))->toBe('Paid via WooCommerce')
        ->and([$unpaid->payment_status, (string) $unpaid->discount_amount, (string) $unpaid->shipping_amount])->toBe(['unpaid', '500.0000', '1000.0000'])
        ->and(Order::query()->where('idempotency_key', 'woo:502')->exists())->toBeFalse();

    // Each type logged; item failures kept (the global tax rate, the grouped product, the USD order).
    $logs = collect($this->tenantJson('GET', '/api/admin/woocommerce/sync/logs', [], $this->staff)->assertOk()->json('data'))->keyBy('sync_type');
    expect($logs['category']['status'])->toBe('success')->and($logs['tax_rate']['status'])->toBe('partial')
        ->and($logs['product']['items_failed'])->toBe(1)->and($logs['order']['error_details'][0]['item'])->toBe('order 502');

    // Stock went back to WooCommerce: every change on a mapped product is pushed (debounced).
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), 'products/batch') && collect($r['update'])->contains(fn (array $u): bool => $u['id'] === 100 && $u['stock_quantity'] === 3));
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), 'products/200/variations/batch'));

    // Running again changes nothing already imported; the unpaid order is paid there now.
    $store = $this->woo;
    $store['orders'][1] = [...$store['orders'][1], 'status' => 'processing', 'date_paid' => '2026-09-29T11:00:00'];
    $this->woo = $store;
    $this->tenantJson('POST', '/api/admin/woocommerce/sync/products', [], $this->staff)->assertAccepted();
    $this->tenantJson('POST', '/api/admin/woocommerce/sync/orders', [], $this->staff)->assertAccepted();
    tenancy()->initialize($this->tenant);
    expect(Product::query()->where('sku', 'RUN-1')->count())->toBe(1)
        ->and(wooStockOf($runner))->toBe(['3.000', '0.000'])
        ->and(Order::query()->where('order_source', 'woocommerce')->count())->toBe(2)
        ->and($unpaid->fresh()->payment_status)->toBe('paid')->and(wooStockOf($tee, $small))->toBe(['2.000', '0.000']);

    // A product made here is pushed on demand, then updated in place.
    tenancy()->initialize($this->tenant);
    $cap = Product::query()->create(['name' => 'Cap', 'sku' => 'CAP-1', 'price' => '3000', 'is_active' => true]);
    $this->tenantJson('POST', "/api/admin/woocommerce/products/{$cap->id}/push", [], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/woocommerce/products/{$cap->id}/push", [], $this->staff)->assertOk();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && str_ends_with($r->url(), 'products/900') && $r['sku'] === 'CAP-1');

    $metrics = $this->tenantJson('GET', '/api/admin/woocommerce/sync/metrics', [], $this->staff)->assertOk()->json('data');
    expect($metrics['mapped'])->toMatchArray(['categories' => 2, 'products' => 3, 'variations' => 2, 'tax_rates' => 1, 'orders' => 2]);

    // Changing a credential pauses sync; the watchdog restarts only an active, stalled chain.
    $settings(['consumer_secret' => 'cs_'.str_repeat('c3', 15)])->assertOk()->assertJsonPath('data.is_active', false);
    tenancy()->initialize($this->tenant);
    expect(app(WooCommerceSettingsService::class)->restartStaleChain())->toBeFalse();
    WooCommerceSettings::query()->update(['is_active' => true, 'last_synced_at' => now()->subHours(2)]);
    expect(app(WooCommerceSettingsService::class)->restartStaleChain())->toBeTrue();
});

it('refuses to switch on when WooCommerce rejects the keys', function (): void {
    $this->wooRejects = true;

    $this->tenantJson('PUT', '/api/admin/woocommerce/settings', ['store_url' => 'https://shop.example.com', 'consumer_key' => 'ck_'.str_repeat('a1', 15), 'consumer_secret' => 'cs_'.str_repeat('b2', 15),
        'is_active' => true, 'import_warehouse_id' => $this->shop->id], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'woocommerce_connection_failed')->assertJsonPath('message', 'WooCommerce refused the consumer key and secret.');
    $this->tenantJson('POST', '/api/admin/woocommerce/settings/test-connection', [], $this->staff)->assertOk()->assertJsonPath('data.connected', false);
});
