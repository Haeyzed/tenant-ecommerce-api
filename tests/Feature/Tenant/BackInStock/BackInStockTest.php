<?php

declare(strict_types=1);

use App\Modules\BackInStock\Models\BackInStockSubscription;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBundleItem;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    // A capability of the Standard tier and above, switched on automatically.
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(TenantSettingsService::class)->set('store_name', 'Ada Stores');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];
    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    $this->adaAuth = ['Authorization' => 'Bearer '.$this->ada->createToken('t', ['customer'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->mug = Product::query()->create(['name' => 'Mug', 'slug' => 'mug', 'price' => '20', 'is_active' => true]);
    $this->hoodie = Product::query()->create(['name' => 'Hoodie', 'slug' => 'hoodie', 'price' => '30', 'product_type' => 'variable', 'is_active' => true]);
    $this->small = ProductVariant::query()->forceCreate(['product_id' => $this->hoodie->id, 'sku' => 'HD-S', 'is_active' => true]);
    $this->large = ProductVariant::query()->forceCreate(['product_id' => $this->hoodie->id, 'sku' => 'HD-L', 'is_active' => true]);
    $this->set = Product::query()->create(['name' => 'Gift set', 'slug' => 'gift-set', 'price' => '50', 'product_type' => 'bundle', 'is_active' => true]);
    ProductBundleItem::query()->create(['bundle_product_id' => $this->set->id, 'child_product_id' => $this->mug->id, 'quantity' => '2']);
    $this->guide = Product::query()->create(['name' => 'Guide', 'slug' => 'guide', 'price' => '5', 'product_type' => 'digital', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->hoodie, $this->small, '3', 'adjustment_in');
});

it('takes one waiting request per item and email, from guests and customers, only while the item is out of stock', function (): void {
    $first = $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', ['email' => 'Guest@Mail.test'])->assertCreated()
        ->assertJsonPath('data.email', 'guest@mail.test')->json('data');
    // A repeat request is a no-op.
    $this->tenantJson('POST', "/api/products/{$this->mug->id}/back-in-stock-alert", ['email' => 'guest@mail.test'])->assertCreated()->assertJsonPath('data.id', $first['id']);
    $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', [])->assertStatus(422);
    // A signed-in customer need not type an email.
    $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', [], $this->adaAuth)->assertCreated()->assertJsonPath('data.email', 'ada@shop.test');

    // In stock, never out of stock, or unknown. (Rate-limited per address: five a minute.)
    $this->tenantJson('POST', '/api/products/hoodie/back-in-stock-alert', ['email' => 'a@mail.test', 'product_variant_id' => $this->small->id])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'item_in_stock');
    $this->tenantJson('POST', '/api/products/hoodie/back-in-stock-alert', ['email' => 'b@mail.test'])->assertStatus(422)->assertJsonPath('meta.error_code', 'item_in_stock');
    $this->tenantJson('POST', '/api/products/guide/back-in-stock-alert', ['email' => 'c@mail.test'])->assertStatus(422)->assertJsonPath('meta.error_code', 'not_stock_tracked');
    $this->tenantJson('POST', '/api/products/no-such-thing/back-in-stock-alert', ['email' => 'd@mail.test'])->assertNotFound();
    $this->tenantJson('POST', '/api/products/hoodie/back-in-stock-alert', ['email' => 'e@mail.test', 'product_variant_id' => 999999])->assertNotFound();

    // One size out of stock: a request for that size.
    $this->tenantJson('POST', '/api/products/hoodie/back-in-stock-alert', ['email' => 'g@mail.test', 'product_variant_id' => $this->large->id])
        ->assertCreated()->assertJsonPath('data.product_variant_id', $this->large->id);

    // Staff see the demand by variant.
    $this->tenantJson('GET', "/api/admin/products/{$this->mug->id}/back-in-stock-subscribers", [], $this->staff)->assertOk()
        ->assertJsonPath('data.waiting', 2)->assertJsonPath('data.by_variant.0.waiting', 2)->assertJsonPath('data.subscribers.1.customer.id', $this->ada->id);
    $this->tenantJson('GET', "/api/admin/products/{$this->hoodie->id}/back-in-stock-subscribers", [], $this->staff)->assertOk()
        ->assertJsonPath('data.by_variant.0.sku', 'HD-L');

    // Account deletion removes the customer's requests and guest requests made with the same address.
    tenancy()->initialize($this->tenant);
    BackInStockSubscription::query()->forceCreate(['product_id' => $this->set->id, 'email' => 'ada@shop.test']);
    app(CustomerService::class)->deleteCustomer($this->ada);
    expect(BackInStockSubscription::query()->where('email', 'ada@shop.test')->count())->toBe(0)
        ->and(BackInStockSubscription::query()->count())->toBe(2);
});

it('alerts everyone waiting once when the item and the bundles it completes come back, and a variant restock answers the product', function (): void {
    $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', ['email' => 'guest@mail.test'])->assertCreated();
    $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', [], $this->adaAuth)->assertCreated();
    $this->tenantJson('POST', '/api/products/gift-set/back-in-stock-alert', ['email' => 'sets@mail.test'])->assertCreated();
    $this->tenantJson('POST', '/api/products/hoodie/back-in-stock-alert', ['email' => 'large@mail.test', 'product_variant_id' => $this->large->id])->assertCreated();

    // One mug is not enough for the set of two; the mug's own waiters are alerted.
    tenancy()->initialize($this->tenant);
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '1', 'adjustment_in');

    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'back_in_stock.available'
        && str_contains($n->subject, 'Mug is back in stock') && str_contains($n->body, '/products/mug'));
    Notification::assertSentOnDemand(TemplatedNotification::class, fn (TemplatedNotification $n, array $channels, AnonymousNotifiable $to): bool => $n->key === 'back_in_stock.available'
        && $to->routes['mail'] === 'guest@mail.test');
    expect(BackInStockSubscription::query()->where('product_id', $this->set->id)->whereNull('notified_at')->count())->toBe(1)
        ->and(BackInStockSubscription::query()->where('product_id', $this->mug->id)->whereNull('notified_at')->count())->toBe(0);

    // A second mug completes the set.
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '-1', 'adjustment_out');
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '2', 'adjustment_in');
    Notification::assertSentOnDemand(TemplatedNotification::class, fn (TemplatedNotification $n, array $channels, AnonymousNotifiable $to): bool => str_contains($n->subject, 'Gift set')
        && $to->routes['mail'] === 'sets@mail.test');
    // Nobody hears twice about the mug: two restocks, one alert each.
    Notification::assertSentOnDemandTimes(TemplatedNotification::class, 2);
    Notification::assertSentToTimes($this->ada, TemplatedNotification::class, 1);

    // The large hoodie: the size's waiters and anyone waiting for the hoodie as a whole.
    BackInStockSubscription::query()->forceCreate(['product_id' => $this->hoodie->id, 'email' => 'any@mail.test']);
    app(InventoryService::class)->adjustStock($this->main, $this->hoodie, $this->large, '2', 'adjustment_in');
    Notification::assertSentOnDemand(TemplatedNotification::class, fn (TemplatedNotification $n, array $channels, AnonymousNotifiable $to): bool => str_contains($n->subject, 'Hoodie (HD-L)')
        && $to->routes['mail'] === 'large@mail.test');
    Notification::assertSentOnDemand(TemplatedNotification::class, fn (TemplatedNotification $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'any@mail.test');
    expect(BackInStockSubscription::query()->whereNull('notified_at')->count())->toBe(0);

    // Sold out again: the same address may ask again.
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '-2', 'adjustment_out');
    $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', ['email' => 'guest@mail.test'])->assertCreated();
});

it('sends nothing and takes no requests while the capability is switched off', function (): void {
    $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', ['email' => 'guest@mail.test'])->assertCreated();
    $this->tenantJson('POST', '/api/admin/modules/back_in_stock_alerts/disable', [], $this->staff)->assertOk();

    $this->tenantJson('POST', '/api/products/mug/back-in-stock-alert', ['email' => 'late@mail.test'])->assertForbidden();
    tenancy()->initialize($this->tenant);
    app(InventoryService::class)->adjustStock($this->main, $this->mug, null, '4', 'adjustment_in');
    Notification::assertSentOnDemandTimes(TemplatedNotification::class, 0);
    expect(BackInStockSubscription::query()->whereNull('notified_at')->count())->toBe(1);
});
