<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\SalesQuotations\Services\SalesQuotationService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(TenantSettingsService::class)->set('payment_mode', 'live');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    $this->adaAuth = ['Authorization' => 'Bearer '.$this->ada->createToken('t', ['customer'])->plainTextToken];
    $bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@shop.test', 'password' => 'Secret123']);
    $this->bolaAuth = ['Authorization' => 'Bearer '.$bola->createToken('t', ['customer'])->plainTextToken];
    $address = new Address;
    $address->forceFill(['customer_id' => $this->ada->id, 'recipient_name' => 'Ada Obi', 'address_line_1' => '1 Marina', 'country_id' => 1, 'is_default' => true])->save();

    app(WarehouseService::class)->ensureDefault();
    $main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40000', 'is_active' => true]);
    $this->bag = Product::query()->create(['name' => 'Tote', 'price' => '5000', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($main, $this->shoe, null, '20', 'adjustment_in');
    app(TaxService::class)->createTaxRate(['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => '7.5']);
    // A storewide promotion never applies to a converted quote.
    app(PromotionService::class)->createPromotion(['name' => 'Everything 10%', 'trigger' => 'automatic', 'scope' => 'order', 'discount_type' => 'percentage', 'discount_value' => 10]);

    foreach (['sales_quotations', 'sales_agents'] as $module) {
        $this->tenantJson('POST', "/api/admin/modules/{$module}/enable", [], $this->staff)->assertOk();
    }

    $this->agent = $this->tenantJson('POST', '/api/admin/sales-agents', ['name' => 'Chidi', 'phone' => '+2348000000003'], $this->staff)->assertCreated()->json('data');
});

it('quotes a customer request and turns the accepted quote into an order at the quoted prices', function (): void {
    $request = $this->tenantJson('POST', '/api/quotation-requests', ['items' => [['product_id' => $this->shoe->id, 'quantity' => 10]], 'notes' => 'Bulk for our team',
        'sales_agent_code' => $this->agent['agent_code']], $this->adaAuth)->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.quotation', null)->json('data');

    $this->tenantJson('GET', "/api/quotation-requests/{$request['id']}", [], $this->bolaAuth)->assertNotFound();
    $this->tenantJson('POST', "/api/quotation-requests/{$request['id']}/accept", [], $this->adaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'quotation_not_sent');

    // Staff price every line: 10 × 35,000 less 5,000.
    $line = $request['items'][0]['id'];
    $this->tenantJson('POST', "/api/admin/sales-quotation-requests/{$request['id']}/send", ['items' => [['request_item_id' => $line + 999, 'unit_price' => '1']]], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'quotation_lines_mismatch');
    $sent = $this->tenantJson('POST', "/api/admin/sales-quotation-requests/{$request['id']}/send", [
        'items' => [['request_item_id' => $line, 'unit_price' => '35000', 'discount_amount' => '5000']], 'valid_until' => now()->addDays(14)->toDateString(),
    ], $this->staff)->assertOk()->assertJsonPath('data.status', 'quoted')->assertJsonPath('data.sales_agent.id', $this->agent['id'])->json('data.quotation');
    expect($sent)->toMatchArray(['quotation_number' => 'SQ-000001', 'status' => 'sent', 'subtotal' => '350000.0000', 'discount_amount' => '5000.0000', 'total' => '345000.0000']);
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'sales_quotation.sent'
        && str_contains($n->body, 'SQ-000001') && str_contains($n->body, '/account/quotations/'.$request['id']));

    $this->tenantJson('POST', "/api/admin/sales-quotation-requests/{$request['id']}/cancel", [], $this->staff)->assertStatus(422);
    $this->tenantJson('GET', '/api/quotation-requests', [], $this->adaAuth)->assertOk()->assertJsonPath('data.0.quotation.total', '345000.0000');

    // Accepted: a pending order, tax at the default address, no promotion, stock reserved.
    $order = $this->tenantJson('POST', "/api/quotation-requests/{$request['id']}/accept", [], $this->adaAuth)->assertCreated()->json('data');
    expect($order)->toMatchArray(['status' => 'pending', 'payment_status' => 'unpaid', 'subtotal' => '350000.0000', 'discount_amount' => '5000.0000', 'tax_amount' => '25875.0000', 'total' => '370875.0000'])
        ->and($order['promotions'])->toBe([])
        ->and($order['payment_expires_at'])->toBeNull()
        ->and($order['shipping_address']['line1'])->toBe('1 Marina');

    tenancy()->initialize($this->tenant);
    expect(OrderItem::query()->where('order_id', $order['id'])->value('price_source'))->toBe('quotation')
        ->and((string) Inventory::query()->where('product_id', $this->shoe->id)->value('reserved_quantity'))->toBe('10.000');
    $this->tenantJson('GET', "/api/admin/orders/{$order['id']}", [], $this->staff)->assertOk()
        ->assertJsonPath('data.order_source', 'online')->assertJsonPath('data.sales_agent_id', $this->agent['id']);
    $this->tenantJson('GET', "/api/quotation-requests/{$request['id']}", [], $this->adaAuth)->assertOk()
        ->assertJsonPath('data.status', 'converted')->assertJsonPath('data.quotation.status', 'accepted')->assertJsonPath('data.quotation.converted_order_id', $order['id']);
    $this->tenantJson('POST', "/api/quotation-requests/{$request['id']}/accept", [], $this->adaAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');

    $section = $this->tenantJson('GET', '/api/admin/dashboard/sales_quotations?range=today&compare=none', [], $this->staff)->assertOk()->json('data');
    expect(collect($section['kpis'])->pluck('value', 'key')->all())->toMatchArray(['quotations_sent' => 1, 'quotations_accepted' => 1, 'accepted_value' => '370875.0000']);
});

it('lets staff draft, check stock, reject and expire quotations', function (): void {
    // A phone enquiry with no customer, held as a draft, then cancelled.
    $draft = $this->tenantJson('POST', '/api/admin/sales-quotation-requests', ['draft' => true, 'items' => [['product_id' => $this->bag->id, 'quantity' => 3]]], $this->staff)
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.customer', null)->json('data');

    // No stock for the bag: refused when quotes need stock.
    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('allow_quotation_without_stock', false);
    $this->tenantJson('POST', "/api/admin/sales-quotation-requests/{$draft['id']}/send", ['items' => [['request_item_id' => $draft['items'][0]['id'], 'unit_price' => '4500']]], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'quotation_out_of_stock');
    $this->tenantJson('POST', "/api/admin/sales-quotation-requests/{$draft['id']}/cancel", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'cancelled');

    // Staff quote Ada, and reject on her behalf.
    $forAda = $this->tenantJson('POST', '/api/admin/sales-quotation-requests', ['customer_id' => $this->ada->id, 'sales_agent_id' => $this->agent['id'],
        'items' => [['product_id' => $this->shoe->id, 'quantity' => 2]]], $this->staff)->assertCreated()->assertJsonPath('data.status', 'sent')->json('data');
    $quote = $this->tenantJson('POST', "/api/admin/sales-quotation-requests/{$forAda['id']}/send", ['items' => [['request_item_id' => $forAda['items'][0]['id'], 'unit_price' => '38000']]], $this->staff)
        ->assertOk()->json('data.quotation');
    $this->tenantJson('POST', "/api/admin/sales-quotations/{$quote['id']}/reject", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->tenantJson('GET', "/api/admin/sales-quotation-requests/{$forAda['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'rejected');

    // A quote valid until today can be accepted today, not after.
    $late = $this->tenantJson('POST', '/api/quotation-requests', ['items' => [['product_id' => $this->shoe->id, 'quantity' => 1]]], $this->adaAuth)->assertCreated()->json('data');
    $lateQuote = $this->tenantJson('POST', "/api/admin/sales-quotation-requests/{$late['id']}/send", ['items' => [['request_item_id' => $late['items'][0]['id'], 'unit_price' => '39000']],
        'valid_until' => now()->toDateString()], $this->staff)->assertOk()->json('data.quotation');

    $this->travel(2)->days();
    $this->tenantJson('POST', "/api/admin/sales-quotations/{$lateQuote['id']}/accept", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'quotation_expired');

    tenancy()->initialize($this->tenant);
    expect(app(SalesQuotationService::class)->expireQuotations())->toBe(1);
    $this->tenantJson('GET', '/api/admin/sales-quotation-requests?status=expired', [], $this->staff)->assertOk()->assertJsonPath('data.0.id', $late['id'])
        ->assertJsonPath('data.0.quotation.status', 'expired');
});
