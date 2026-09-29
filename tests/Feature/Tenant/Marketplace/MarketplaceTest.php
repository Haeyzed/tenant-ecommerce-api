<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Marketplace\Models\SellerLedgerEntry;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    app(TenantSettingsService::class)->set('payment_mode', 'live');
    app(TenantSettingsService::class)->set('seller_payout_hold_days', 0);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->shop = Warehouse::query()->firstOrFail();

    $this->tenantJson('POST', '/api/admin/modules/marketplace/enable', [], $this->staff)->assertOk();
});

/**
 * An approved seller with a token.
 *
 * @return array{id: int, auth: array<string, string>}
 */
function approvedSeller(string $name, string $email): array
{
    $seller = test()->tenantJson('POST', '/api/seller/auth/register', ['business_name' => $name, 'email' => $email, 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'])
        ->assertCreated()->json('data');
    test()->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/approve", [], test()->staff)->assertOk();
    $token = test()->tenantJson('POST', '/api/seller/auth/login', ['email' => $email, 'password' => 'Secret123!'])->assertOk()->json('data.token');

    return ['id' => $seller['id'], 'auth' => ['Authorization' => 'Bearer '.$token]];
}

/**
 * Posted journal lines of the entries whose key starts with the prefix.
 *
 * @return array<string, string> "system_key:type" => amount
 */
function sellerJournal(string $keyPrefix): array
{
    tenancy()->initialize(test()->tenant);

    return DB::connection('tenant')->table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('e.posting_key', 'like', $keyPrefix.'%')
        ->groupBy('a.system_key', 'l.type')
        ->selectRaw("CONCAT(a.system_key, ':', l.type) as k, SUM(l.amount) as amount")
        ->pluck('amount', 'k')->map(fn ($a): string => (string) $a)->all();
}

function sellerPosSale(array $register, int $productId, int $quantity, string $cash): TestResponse
{
    return test()->tenantJson('POST', '/api/admin/pos/sales', ['register_id' => $register['id'], 'idempotency_key' => Str::uuid()->toString(),
        'lines' => [['product_id' => $productId, 'quantity' => $quantity]], 'payments' => [['method' => 'cash', 'amount' => $cash]]], test()->staff);
}

it('takes a seller from application to approved listings', function (): void {
    $application = $this->tenantJson('POST', '/api/seller/auth/register', ['business_name' => 'Ade Crafts', 'email' => 'Ade@Crafts.test', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'seller.new_seller_application' && str_contains($n->body, 'Ade Crafts'));

    // Only approved sellers sign in.
    $this->tenantJson('POST', '/api/seller/auth/login', ['email' => 'ade@crafts.test', 'password' => 'Secret123!'])->assertForbidden()
        ->assertJsonPath('meta.error_code', 'seller_not_approved')->assertJsonPath('meta.details.status', 'pending');
    $this->tenantJson('POST', "/api/admin/sellers/{$application['id']}/approve", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'approved');
    $login = $this->tenantJson('POST', '/api/seller/auth/login', ['email' => 'ade@crafts.test', 'password' => 'Secret123!'])->assertOk()->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['token']];
    expect($login['seller'])->toMatchArray(['business_name' => 'Ade Crafts', 'effective_commission_rate' => '0.0000']);

    // A seller token is not a customer or staff token.
    $this->tenantJson('GET', '/api/account', [], $auth)->assertUnauthorized();
    $this->tenantJson('GET', '/api/admin/sellers', [], $auth)->assertUnauthorized();

    // Listings: forbidden fields are refused; a new product waits for approval.
    $this->tenantJson('POST', '/api/seller/products', ['name' => 'Basket', 'price' => '8000', 'has_warehouse_pricing' => true], $auth)
        ->assertStatus(422)->assertJsonValidationErrors('has_warehouse_pricing');
    $product = $this->tenantJson('POST', '/api/seller/products', ['name' => 'Woven Basket', 'price' => '8000', 'is_active' => true], $auth)
        ->assertCreated()->assertJsonPath('data.moderation_status', 'pending')->assertJsonPath('data.seller_id', $application['id'])->json('data');
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'seller.new_product_pending_approval');
    expect(collect($this->tenantJson('GET', '/api/products')->assertOk()->json('data'))->pluck('name')->all())->not->toContain('Woven Basket');

    $this->tenantJson('GET', '/api/admin/seller-products', [], $this->staff)->assertOk()->assertJsonPath('data.0.id', $product['id']);
    $this->tenantJson('POST', "/api/admin/seller-products/{$product['id']}/approve", [], $this->staff)->assertOk()->assertJsonPath('data.moderation_status', 'approved');
    expect(collect($this->tenantJson('GET', '/api/products')->assertOk()->json('data'))->pluck('name')->all())->toContain('Woven Basket');

    // Edits keep the approval; another seller cannot touch the product.
    $this->tenantJson('PATCH', "/api/seller/products/{$product['id']}", ['price' => '8500'], $auth)->assertOk()->assertJsonPath('data.moderation_status', 'approved');
    $other = approvedSeller('Bisi Wares', 'bisi@wares.test');
    $this->tenantJson('GET', "/api/seller/products/{$product['id']}", [], $other['auth'])->assertNotFound();
    $this->tenantJson('GET', '/api/seller/products', [], $other['auth'])->assertOk()->assertJsonCount(0, 'data');

    // Suspension ends the sessions and takes the products off sale.
    $this->tenantJson('POST', "/api/admin/sellers/{$application['id']}/suspend", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->tenantJson('GET', '/api/seller/profile', [], $auth)->assertUnauthorized();
    tenancy()->initialize($this->tenant);
    expect(Product::query()->findOrFail($product['id'])->is_active)->toBeFalse();
});

it('lets a seller build variable, digital and bundle listings from its own products only', function (): void {
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('seller_product_approval_required', false);
    $size = $this->tenantJson('POST', '/api/admin/product-options', ['name' => 'Size', 'values' => ['S', 'M']], $this->staff)->assertCreated()->json('data');
    $storeProduct = Product::query()->create(['name' => 'House Mug', 'price' => '3000', 'is_active' => true]);

    $ade = approvedSeller('Ade Crafts', 'ade@crafts.test');
    $bisi = approvedSeller('Bisi Wares', 'bisi@wares.test');

    // Variants are built from the store's options, which sellers can read but not change.
    $this->tenantJson('GET', '/api/seller/product-options', [], $ade['auth'])->assertOk()->assertJsonPath('data.0.name', 'Size');
    $this->tenantJson('POST', '/api/admin/product-options', ['name' => 'Colour', 'values' => ['Red']], $ade['auth'])->assertUnauthorized();
    $shirt = $this->tenantJson('POST', '/api/seller/products', ['product_type' => 'variable', 'name' => 'Adire Shirt', 'price' => '15000', 'is_active' => true], $ade['auth'])
        ->assertCreated()->assertJsonPath('data.product_type', 'variable')->json('data');
    $this->tenantJson('POST', "/api/seller/products/{$shirt['id']}/variants", ['sku' => 'ADIRE-S', 'price' => '15000', 'option_value_ids' => [$size['values'][0]['id']]], $ade['auth'])
        ->assertCreated()->assertJsonPath('data.sku', 'ADIRE-S');
    $this->tenantJson('GET', "/api/seller/products/{$shirt['id']}/variants", [], $ade['auth'])->assertOk()->assertJsonCount(1, 'data');
    $this->tenantJson('POST', "/api/seller/products/{$shirt['id']}/variants", ['sku' => 'X-M', 'option_value_ids' => [$size['values'][1]['id']]], $bisi['auth'])->assertNotFound();

    // Photos and downloads on the seller's own products.
    $this->tenantJson('POST', "/api/seller/products/{$shirt['id']}/media", ['image' => UploadedFile::fake()->image('shirt.jpg', 400, 400)], $ade['auth'])->assertCreated();
    $ebook = $this->tenantJson('POST', '/api/seller/products', ['product_type' => 'digital', 'name' => 'Dye Guide', 'price' => '2000', 'is_active' => true], $ade['auth'])->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/seller/products/{$ebook['id']}/digital-files", ['file' => UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf')], $ade['auth'])->assertCreated();
    $this->tenantJson('POST', "/api/seller/products/{$ebook['id']}/media", ['image' => UploadedFile::fake()->image('x.jpg', 400, 400)], $bisi['auth'])->assertNotFound();

    // A bundle takes only the seller's own products.
    $basket = $this->tenantJson('POST', '/api/seller/products', ['name' => 'Basket', 'price' => '8000'], $ade['auth'])->assertCreated()->json('data');
    $this->tenantJson('POST', '/api/seller/products', ['product_type' => 'bundle', 'name' => 'Mixed Set', 'price' => '9000',
        'bundle_items' => [['child_product_id' => $storeProduct->id, 'quantity' => 1]]], $ade['auth'])->assertStatus(422)->assertJsonPath('meta.error_code', 'bundle_child_not_owned');
    $set = $this->tenantJson('POST', '/api/seller/products', ['product_type' => 'bundle', 'name' => 'Craft Set', 'price' => '9500',
        'bundle_items' => [['child_product_id' => $basket['id'], 'quantity' => 1]]], $ade['auth'])->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/seller/products/{$set['id']}/bundle-items", ['child_product_id' => $ebook['id'], 'quantity' => 1], $ade['auth'])->assertCreated();
    $this->tenantJson('POST', "/api/seller/products/{$set['id']}/bundle-items", ['child_product_id' => $storeProduct->id, 'quantity' => 1], $ade['auth'])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'bundle_child_not_owned');
    $this->tenantJson('GET', "/api/seller/products/{$set['id']}", [], $ade['auth'])->assertOk()->assertJsonCount(2, 'data.bundle_items');
});

it('switches the marketplace off only once sellers are paid, then winds down', function (): void {
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('seller_product_approval_required', false);
    $seller = approvedSeller('Ade Crafts', 'ade@crafts.test');
    $basket = $this->tenantJson('POST', '/api/seller/products', ['name' => 'Woven Basket', 'price' => '10000', 'is_active' => true], $seller['auth'])->assertCreated()->json('data');
    Product::query()->create(['name' => 'House Mug', 'price' => '3000', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->shop, Product::query()->findOrFail($basket['id']), null, '3', 'adjustment_in');
    $this->tenantJson('POST', '/api/seller/push-tokens', ['token' => 'device-token-1', 'platform' => 'android'], $seller['auth'])->assertCreated();

    $this->tenantJson('POST', '/api/admin/modules/pos/enable', [], $this->staff)->assertOk();
    $this->tenantJson('PUT', '/api/admin/pos/settings', ['cash_register_enabled' => false], $this->staff)->assertOk();
    $register = $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Front'], $this->staff)->assertCreated()->json('data');
    $sale = sellerPosSale($register, $basket['id'], 1, '10000')->assertCreated()->json('data.sale');

    $this->tenantJson('GET', "/api/admin/orders?seller_id={$seller['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.0.id', $sale['id']);
    $strip = collect($this->tenantJson('GET', '/api/admin/sellers/metrics', [], $this->staff)->assertOk()->json('data'))->pluck('value', 'key')->all();
    expect($strip)->toMatchArray(['approved' => 1, 'pending' => 0, 'payable_balance' => '10000.0000']);
    $this->tenantJson('GET', '/api/admin/lookups/seller-groups', [], $this->staff)->assertOk();

    // Owed money blocks switching the marketplace off (§11.5).
    $this->tenantJson('POST', '/api/admin/modules/marketplace/disable', [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'module_disable_blocked');
    $today = now()->toDateString();
    $payout = $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/payouts", ['from' => $today, 'to' => $today], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/payouts/{$payout['id']}/mark-paid", [], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])->assertOk();
    $this->tenantJson('POST', '/api/admin/modules/marketplace/disable', [], $this->staff)->assertOk();

    // Wind-down: the seller still signs in and reads, but lists nothing new; its products leave the storefront.
    $this->tenantJson('POST', '/api/seller/auth/login', ['email' => 'ade@crafts.test', 'password' => 'Secret123!'])->assertOk();
    $this->tenantJson('GET', '/api/seller/payouts', [], $seller['auth'])->assertOk()->assertJsonPath('data.0.status', 'paid');
    $this->tenantJson('POST', '/api/seller/products', ['name' => 'Another', 'price' => '100'], $seller['auth'])->assertForbidden();
    $this->tenantJson('POST', '/api/seller/auth/register', ['business_name' => 'Late', 'email' => 'late@x.test', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'])->assertForbidden();
    $names = collect($this->tenantJson('GET', '/api/products')->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toContain('House Mug')->not->toContain('Woven Basket');
    $this->tenantJson('POST', '/api/cart/items', ['product_id' => $basket['id'], 'quantity' => 1])->assertStatus(422);
});

it('records seller earnings on a sale, pays them out once and nets a void', function (): void {
    $this->tenantJson('POST', '/api/admin/modules/accounting/enable', [], $this->staff)->assertOk();
    $year = now()->year;
    $this->tenantJson('POST', '/api/admin/accounting/fiscal-years', ['name' => "FY{$year}", 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31"], $this->staff)->assertCreated();

    $seller = approvedSeller('Ade Crafts', 'ade@crafts.test');
    $group = $this->tenantJson('POST', '/api/admin/seller-groups', ['name' => 'Verified', 'default_commission_rate' => '15'], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/assign-group", ['seller_group_id' => $group['id']], $this->staff)->assertOk()
        ->assertJsonPath('data.effective_commission_rate', '15.0000');

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('seller_product_approval_required', false);
    $product = $this->tenantJson('POST', '/api/seller/products', ['name' => 'Woven Basket', 'price' => '10000', 'is_active' => true], $seller['auth'])
        ->assertCreated()->assertJsonPath('data.moderation_status', 'approved')->json('data');
    // Consignment: the store holds the seller's stock (UD-20).
    app(InventoryService::class)->adjustStock($this->shop, Product::query()->findOrFail($product['id']), null, '5', 'adjustment_in');

    $this->tenantJson('POST', '/api/admin/modules/pos/enable', [], $this->staff)->assertOk();
    $this->tenantJson('PUT', '/api/admin/pos/settings', ['cash_register_enabled' => false], $this->staff)->assertOk();
    $register = $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->shop->id, 'name' => 'Front'], $this->staff)->assertCreated()->json('data');

    // Two baskets: 20,000 gross, 15% = 3,000 to the store, 17,000 to the seller, payable at once (no hold).
    $sale = sellerPosSale($register, $product['id'], 2, '20000')->assertCreated()->json('data.sale');
    $ledger = $this->tenantJson('GET', '/api/seller/ledger', [], $seller['auth'])->assertOk()->json('data');
    expect($ledger)->toHaveCount(1)
        ->and($ledger[0])->toMatchArray(['entry_type' => 'sale', 'gross_amount' => '20000.0000', 'commission_rate_applied' => '15.0000', 'commission_amount' => '3000.0000', 'net_payable' => '17000.0000'])
        ->and($ledger[0]['available_at'])->not->toBeNull();
    $this->tenantJson('GET', '/api/seller/orders', [], $seller['auth'])->assertOk()->assertJsonPath('data.0.order_number', $sale['order_number'])
        ->assertJsonPath('data.0.items.0.quantity', '2.000')->assertJsonMissingPath('data.0.customer');

    // The seller's share leaves Sales Revenue: the store's cut and what it owes the seller.
    expect(sellerJournal('seller_commission:'.$sale['id']))->toEqualCanonicalizing([
        'sales_revenue:debit' => '20000.0000', 'commission_revenue:credit' => '3000.0000', 'accounts_payable_sellers:credit' => '17000.0000',
    ]);

    $today = now()->toDateString();
    $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/payouts", ['from' => $today, 'to' => $today, 'preview' => true], $this->staff)->assertOk()
        ->assertJsonPath('data.net_payable', '17000.0000')->assertJsonPath('data.entries', 1);
    $payout = $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/payouts", ['from' => $today, 'to' => $today], $this->staff)->assertCreated()
        ->assertJsonPath('data.net_payable', '17000.0000')->assertJsonPath('data.status', 'pending')->json('data');
    $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/payouts", ['from' => $today, 'to' => $today], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'payout_not_positive');
    $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/payouts/{$payout['id']}/mark-paid", ['reference' => 'TRF-501'], [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertOk()->assertJsonPath('data.status', 'paid');
    $this->tenantJson('GET', '/api/seller/payouts', [], $seller['auth'])->assertOk()->assertJsonPath('data.0.reference', 'TRF-501');
    expect(sellerJournal('seller_payout:'.$payout['id']))->toEqualCanonicalizing(['accounts_payable_sellers:debit' => '17000.0000', 'cash_bank:credit' => '17000.0000']);

    // A voided sale: its refund and its cancellation both point at the same money, reversed once.
    $voided = sellerPosSale($register, $product['id'], 1, '10000')->assertCreated()->json('data.sale');
    $this->tenantJson('POST', "/api/admin/pos/sales/{$voided['id']}/void", ['reason' => 'Damaged'], $this->staff)->assertOk();

    tenancy()->initialize($this->tenant);
    $entries = SellerLedgerEntry::query()->where('order_id', $voided['id'])->get();
    expect(bcadd((string) $entries->sum('gross_amount'), '0', 4))->toBe('0.0000')
        ->and($entries->where('entry_type', 'reversal')->count())->toBeGreaterThanOrEqual(1);
    $this->tenantJson('GET', '/api/seller/profile', [], $seller['auth'])->assertOk()->assertJsonPath('data.balance.available', '0.0000');
    $this->tenantJson('POST', "/api/admin/sellers/{$seller['id']}/payouts", ['from' => $today, 'to' => $today], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'payout_not_positive');

    $section = $this->tenantJson('GET', '/api/admin/dashboard/marketplace?range=today&compare=none', [], $this->staff)->assertOk()->json('data');
    expect(collect($section['kpis'])->pluck('value', 'key')->all())->toMatchArray(['approved_sellers' => 1, 'seller_sales' => '20000.0000', 'payable_to_sellers' => '0.0000'])
        ->and($section['tables'][0]['rows'][0]['name'])->toBe('Ade Crafts');
});
