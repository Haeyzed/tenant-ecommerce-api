<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    $this->mug = Product::query()->create(['name' => 'Mug', 'price' => '10', 'is_active' => true]);
    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    $this->bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@shop.test', 'password' => 'Secret123']);
    $this->adaAuth = ['Authorization' => 'Bearer '.$this->ada->createToken('t', ['customer'])->plainTextToken];
    $this->bolaAuth = ['Authorization' => 'Bearer '.$this->bola->createToken('t', ['customer'])->plainTextToken];
});

it('moderates reviews, keeps one per customer and follows approved ratings', function (): void {
    $this->tenantJson('POST', '/api/products/mug/reviews', ['rating' => 5, 'body' => 'Great mug'])->assertUnauthorized();
    $this->tenantJson('POST', '/api/products/mug/reviews', ['rating' => 6, 'body' => 'Great mug'], $this->adaAuth)->assertStatus(422)->assertJsonValidationErrors('rating');

    $first = $this->tenantJson('POST', '/api/products/mug/reviews', ['rating' => 5, 'body' => 'Great mug'], $this->adaAuth)->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.is_verified_purchase', false)->json('data.id');
    Notification::assertSentTo(User::query()->where('email', 'owner@a.test')->firstOrFail(), TemplatedNotification::class, fn ($n): bool => $n->key === 'review.pending_moderation');

    // A second submission replaces the first.
    $again = $this->tenantJson('POST', '/api/products/mug/reviews', ['rating' => 4, 'body' => 'Great mug, a bit small'], $this->adaAuth)->assertCreated()->json('data.id');
    expect($again)->toBe($first);
    $bola = $this->tenantJson('POST', '/api/products/mug/reviews', ['rating' => 2, 'body' => 'Chipped on arrival'], $this->bolaAuth)->json('data.id');

    $this->tenantJson('GET', '/api/products/mug/reviews')->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('GET', '/api/admin/reviews?status=pending', [], $this->staff)->assertOk()->assertJsonCount(2, 'data');

    $this->tenantJson('POST', "/api/admin/reviews/{$first}/approve", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'approved');
    $this->tenantJson('POST', "/api/admin/reviews/{$bola}/approve", [], $this->staff)->assertOk();
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn ($n): bool => $n->key === 'review.approved');

    $this->tenantJson('GET', '/api/products/mug/reviews')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.rating_average', '3.00')->assertJsonPath('meta.rating_count', 2);

    $this->tenantJson('POST', "/api/admin/reviews/{$bola}/reject", ['reason' => 'Abusive'], $this->staff)->assertOk();
    $this->tenantJson('GET', "/api/products/{$this->mug->id}/reviews")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.rating_average', '4.00')->assertJsonPath('data.0.author', 'Ada');

    $this->tenantJson('DELETE', "/api/admin/reviews/{$first}", [], $this->staff)->assertOk();
    tenancy()->initialize($this->tenant);
    expect(Product::query()->find($this->mug->id)->rating_count)->toBe(0);
});

it('marks verified purchases and can require them', function (): void {
    app(TenantSettingsService::class)->set('reviews_require_verified_purchase', true);
    app(TenantSettingsService::class)->set('review_moderation_required', false);

    $this->tenantJson('POST', '/api/products/mug/reviews', ['rating' => 5, 'body' => 'Great mug'], $this->adaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'verified_purchase_required');

    tenancy()->initialize($this->tenant);
    $orderId = DB::connection('tenant')->table('orders')->insertGetId([
        'order_number' => '900001', 'customer_id' => $this->ada->id, 'status' => Order::DELIVERED, 'currency_code' => 'NGN', 'subtotal' => 10, 'total' => 10,
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('tenant')->table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $this->mug->id, 'name_snapshot' => 'Mug', 'quantity' => 1, 'unit_price' => 10, 'price_source' => 'base', 'line_total' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->tenantJson('POST', '/api/products/mug/reviews', ['rating' => 5, 'body' => 'Great mug'], $this->adaAuth)->assertCreated()
        ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.is_verified_purchase', true);
    $this->tenantJson('GET', '/api/products/mug/reviews?verified=1')->assertOk()->assertJsonPath('data.0.is_verified_purchase', true);
});

it('keeps a wishlist by product and moves items to the cart', function (): void {
    app(WarehouseService::class)->ensureDefault();
    app(InventoryService::class)->adjustStock(Warehouse::query()->firstOrFail(), $this->mug, null, '5', 'adjustment_in');

    $this->tenantJson('GET', '/api/wishlist')->assertUnauthorized();
    $this->tenantJson('POST', '/api/wishlist', ['product_id' => $this->mug->id], $this->adaAuth)->assertCreated();
    $this->tenantJson('POST', '/api/wishlist', ['product_id' => $this->mug->id], $this->adaAuth)->assertCreated();
    $this->tenantJson('GET', '/api/wishlist', [], $this->adaAuth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product.name', 'Mug');
    $this->tenantJson('GET', '/api/wishlist', [], $this->bolaAuth)->assertOk()->assertJsonCount(0, 'data');

    $this->tenantJson('POST', "/api/wishlist/{$this->mug->id}/move-to-cart", ['quantity' => 2], $this->bolaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'not_in_wishlist');
    $this->tenantJson('POST', "/api/wishlist/{$this->mug->id}/move-to-cart", ['quantity' => 2], $this->adaAuth)->assertOk();

    $this->tenantJson('GET', '/api/wishlist', [], $this->adaAuth)->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('GET', '/api/cart', [], $this->adaAuth)->assertOk()->assertJsonPath('data.quote.lines.0.quantity', '2.000');

    $this->tenantJson('POST', '/api/wishlist', ['product_id' => $this->mug->id], $this->adaAuth)->assertCreated();
    $this->tenantJson('DELETE', "/api/wishlist/{$this->mug->id}", [], $this->adaAuth)->assertOk();
    $this->tenantJson('GET', '/api/wishlist', [], $this->adaAuth)->assertOk()->assertJsonCount(0, 'data');
});
