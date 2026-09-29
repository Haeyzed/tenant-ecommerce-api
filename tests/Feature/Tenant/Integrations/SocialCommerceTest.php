<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Integrations\SocialCommerce\Drivers\TikTokShopDriver;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceProductMap;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Orders\Models\Order;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(TenantSettingsService::class)->set('payment_mode', 'live');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];
    app(WarehouseService::class)->ensureDefault();
    $this->shop = Warehouse::query()->firstOrFail();

    $this->runner = Product::query()->create(['name' => 'Runner', 'sku' => 'RUN-1', 'price' => '40000', 'is_active' => true]);
    $this->hidden = Product::query()->create(['name' => 'Staff tee', 'sku' => 'TEE-X', 'price' => '5000', 'is_active' => true]);
    $this->hidden->forceFill(['social_commerce_excluded_channels' => ['facebook_shop']])->save();
    $this->ebook = Product::query()->create(['name' => 'Guide', 'price' => '2000', 'product_type' => 'digital', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->shop, $this->runner, null, '4', 'adjustment_in');

    $this->tenantJson('POST', '/api/admin/modules/social_commerce/enable', [], $this->staff)->assertOk();

    // Meta's Graph API: catalogue 111, commerce account 222; a revoked token for catalogue 999.
    Http::fake(function (Request $request) {
        $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');

        return match (true) {
            str_contains($path, '999') => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400),
            str_ends_with($path, '111') => Http::response(['id' => '111', 'name' => 'Main catalogue']),
            str_ends_with($path, '111/items_batch') => Http::response(['handles' => ['h1']]),
            str_ends_with($path, '222/commerce_orders') => Http::response(['data' => [[
                'id' => 'FB-1', 'order_status' => ['state' => 'CREATED'], 'created' => '2026-09-29T09:00:00+00:00',
                'buyer_details' => ['name' => 'Ada Obi', 'email' => 'ada@buyer.test'],
                'shipping_address' => ['name' => 'Ada Obi', 'street1' => '1 Marina', 'city' => 'Lagos', 'postal_code' => '100001', 'country' => 'NG'],
                'estimated_payment_details' => ['subtotal' => ['items' => ['amount' => '40000.00']], 'shipping' => ['amount' => '2000.00'], 'total_amount' => ['amount' => '45000.00', 'currency' => 'NGN']],
                'items' => ['data' => [['retailer_id' => 'p'.test()->runner->id, 'quantity' => 1, 'price_per_unit' => ['amount' => '40000.00'], 'tax_details' => ['estimated_tax' => ['amount' => '3000.00']]]]],
            ]], 'paging' => ['cursors' => ['after' => 'x']]]),
            default => Http::response([], 404),
        };
    });
});

it('lists the catalogue from our own prices and stock, imports native-checkout orders and honours channel rules', function (): void {
    $connect = fn (array $body) => $this->tenantJson('POST', '/api/admin/social-commerce/accounts', $body, $this->staff);

    $connect(['channel' => 'instagram', 'access_token' => 'IGQ-token', 'account_reference' => '111', 'sync_orders' => true])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'orders_not_supported');
    $connect(['channel' => 'facebook_shop', 'access_token' => 'EAAB-old', 'account_reference' => '999', 'sync_orders' => false])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'social_commerce_connection_failed')
        ->assertJsonPath('message', 'The Meta access token has expired or been revoked. Reconnect the account.');
    $connect(['channel' => 'facebook_shop', 'access_token' => 'EAAB-token', 'account_reference' => '111/../me'])->assertStatus(422);
    $connect(['channel' => 'facebook_shop', 'access_token' => 'EAAB-token', 'account_reference' => '111', 'fulfilment_warehouse_id' => $this->shop->id])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'order_account_required');

    // Connecting runs the first sync (queues run inline in tests).
    $account = $connect(['channel' => 'facebook_shop', 'access_token' => 'EAAB-token', 'account_reference' => '111', 'order_account_reference' => '222',
        'fulfilment_warehouse_id' => $this->shop->id])->assertCreated()->assertJsonPath('data.has_access_token', true)->assertJsonMissingPath('data.access_token')->json('data');

    // Only the runner: the tee is held back from Facebook Shop and the e-book is not physical.
    Http::assertSent(function (Request $r): bool {
        if (! str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '111/items_batch')) {
            return false;
        }

        $items = json_decode((string) $r['requests'], true);

        return count($items) === 1 && $items[0]['data']['id'] === 'p'.test()->runner->id && $items[0]['data']['price'] === '40000.00 NGN' && $items[0]['data']['inventory'] === 4;
    });
    tenancy()->initialize($this->tenant);
    expect(SocialCommerceProductMap::query()->pluck('sync_status', 'product_id')->all())->toBe([$this->runner->id => 'synced']);

    // The native-checkout order: paid there, confirmed here, stock deducted at the fulfilment warehouse.
    $order = Order::query()->where('idempotency_key', 'social:'.$account['id'].':FB-1')->firstOrFail();
    expect([$order->order_source, $order->payment_status, (string) $order->total, (string) $order->tax_amount])->toBe(['social', 'paid', '45000.0000', '3000.0000'])
        ->and((string) Inventory::query()->where('product_id', $this->runner->id)->value('quantity'))->toBe('3.000');

    // Running again imports nothing twice.
    $this->tenantJson('POST', "/api/admin/social-commerce/accounts/{$account['id']}/sync/orders", [], $this->staff)->assertAccepted();
    tenancy()->initialize($this->tenant);
    expect(Order::query()->where('order_source', 'social')->count())->toBe(1);

    // Excluding the runner takes its listing down on the next product sync.
    $this->tenantJson('PATCH', "/api/admin/products/{$this->runner->id}", ['social_commerce_excluded_channels' => ['facebook_shop']], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/social-commerce/accounts/{$account['id']}/sync/products", [], $this->staff)->assertAccepted();
    Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '111/items_batch')
        && (json_decode((string) $r['requests'], true)[0]['method'] ?? null) === 'DELETE');
    tenancy()->initialize($this->tenant);
    expect(SocialCommerceProductMap::query()->count())->toBe(0);
    $this->tenantJson('POST', "/api/admin/social-commerce/accounts/{$account['id']}/products/{$this->runner->id}", [], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'product_not_syncable');

    $logs = $this->tenantJson('GET', "/api/admin/social-commerce/sync/logs?account_id={$account['id']}", [], $this->staff)->assertOk()->json('data');
    expect(collect($logs)->pluck('status')->unique()->values()->all())->toBe(['success']);
    $this->tenantJson('GET', '/api/admin/social-commerce/sync/metrics', [], $this->staff)->assertOk()->assertJsonPath('data.0.orders_imported', 1);

    $this->tenantJson('DELETE', "/api/admin/social-commerce/accounts/{$account['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.is_active', false);
    $this->tenantJson('POST', "/api/admin/social-commerce/accounts/{$account['id']}/sync/products", [], $this->staff)->assertStatus(422);
});

it('signs TikTok Shop calls and refuses TikTok while the platform keys are missing', function (): void {
    $expected = hash_hmac('sha256', 'secret'.'/order/202309/orders/search'.'app_keyk1page_size50timestamp1700000000'.'{"a":1}'.'secret', 'secret');
    expect(TikTokShopDriver::sign('secret', '/order/202309/orders/search', ['timestamp' => 1700000000, 'app_key' => 'k1', 'page_size' => 50, 'access_token' => 'x', 'sign' => 'y'], '{"a":1}'))->toBe($expected);

    config(['integrations.social_commerce.tiktok_app_key' => null]);
    $this->tenantJson('POST', '/api/admin/social-commerce/accounts', ['channel' => 'tiktok_shop', 'access_token' => 'tt', 'account_reference' => 'cipher1', 'sync_orders' => false], $this->staff)
        ->assertStatus(422)->assertJsonPath('message', 'TikTok Shop is not set up on this platform yet.');
});
