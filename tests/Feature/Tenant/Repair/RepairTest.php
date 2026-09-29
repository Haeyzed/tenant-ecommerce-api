<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
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
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->shop = Warehouse::query()->firstOrFail();
    $this->shop->forceFill(['country_id' => 1])->save();
    app(TaxService::class)->createTaxRate(['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => '7.5']);
    $this->screen = Product::query()->create(['name' => 'Screen', 'price' => '30000', 'cost_price' => '18000', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->shop, $this->screen, null, '3', 'adjustment_in');

    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'phone' => '+2348000000001', 'password' => 'Secret123']);
    $bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@shop.test', 'password' => 'Secret123']);
    $this->adaAuth = ['Authorization' => 'Bearer '.$this->ada->createToken('t', ['customer'])->plainTextToken];
    $this->bolaAuth = ['Authorization' => 'Bearer '.$bola->createToken('t', ['customer'])->plainTextToken];

    $this->tenantJson('POST', '/api/admin/modules/repair/enable', [], $this->staff)->assertOk();
});

function screenStock(): string
{
    tenancy()->initialize(test()->tenant);

    return (string) Inventory::query()->where('product_id', test()->screen->id)->value('quantity');
}

it('takes an item from intake through an approved estimate, parts, invoice and pickup', function (): void {
    $job = $this->tenantJson('POST', '/api/admin/repair-jobs', ['customer_id' => $this->ada->id, 'item_description' => 'Phone, cracked screen', 'warehouse_id' => $this->shop->id], $this->staff)
        ->assertCreated()->assertJsonPath('data.status', 'received')->assertJsonPath('data.customer_phone', '+2348000000001')->json('data');
    $url = "/api/admin/repair-jobs/{$job['id']}";

    $this->tenantJson('POST', "{$url}/labor", ['description' => 'Bench fee', 'amount' => '2000'], $this->staff)->assertStatus(422);
    $this->tenantJson('PATCH', "{$url}/status", ['status' => 'diagnosing'], $this->staff)->assertOk();
    $this->tenantJson('POST', "{$url}/parts", ['product_id' => $this->screen->id, 'quantity' => 1], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');
    $this->tenantJson('POST', "{$url}/labor", ['description' => 'Bench fee', 'amount' => '2000'], $this->staff)->assertCreated();

    // The estimate goes to the customer, who must approve before the repair starts.
    $this->tenantJson('PATCH', "{$url}/diagnosis", ['diagnosis_notes' => 'Screen and digitiser need replacing.', 'estimated_cost' => '40000'], $this->staff)
        ->assertOk()->assertJsonPath('data.status', 'awaiting_approval');
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'repair_job.diagnosis_ready');
    $this->tenantJson('PATCH', "{$url}/status", ['status' => 'in_repair'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'approval_required');
    $this->tenantJson('PATCH', "/api/account/repair-jobs/{$job['id']}/approve", [], $this->bolaAuth)->assertNotFound();
    $this->tenantJson('PATCH', "/api/account/repair-jobs/{$job['id']}/approve", [], $this->adaAuth)->assertOk()->assertJsonMissingPath('data.parts.0.unit_cost_snapshot');
    $this->tenantJson('PATCH', "{$url}/status", ['status' => 'in_repair'], $this->staff)->assertOk()->assertJsonPath('data.status', 'in_repair');

    // Parts leave the shelf when fitted, and go back when removed.
    $part = $this->tenantJson('POST', "{$url}/parts", ['product_id' => $this->screen->id, 'quantity' => 2], $this->staff)->assertCreated()
        ->assertJsonPath('data.unit_cost_snapshot', '18000.0000')->json('data');
    expect(screenStock())->toBe('1.000');
    $this->tenantJson('POST', "{$url}/parts", ['product_id' => $this->screen->id, 'quantity' => 5], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'insufficient_stock');
    $this->tenantJson('DELETE', "{$url}/parts/{$part['id']}", [], $this->staff)->assertOk();
    expect(screenStock())->toBe('3.000');
    $this->tenantJson('POST', "{$url}/parts", ['product_id' => $this->screen->id, 'quantity' => 1], $this->staff)->assertCreated();
    tenancy()->initialize($this->tenant);
    expect(InventoryMovement::query()->where('product_id', $this->screen->id)->where('movement_type', 'repair_consume')->count())->toBe(2);

    // The bill: the part at its price with VAT (30,000 + 2,250), labour untaxed (2,000).
    $invoice = $this->tenantJson('POST', "{$url}/generate-invoice", [], $this->staff)->assertCreated()->assertJsonPath('data.job.order.total', '34250.0000')->json('data');
    $this->tenantJson('POST', "{$url}/generate-invoice", [], $this->staff)->assertStatus(409);
    $this->tenantJson('POST', "{$url}/labor", ['description' => 'Extra', 'amount' => '500'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'repair_job_invoiced');
    tenancy()->initialize($this->tenant);
    $order = Order::query()->with('items')->findOrFail($invoice['order_id']);
    expect($order->items->pluck('stock_already_deducted')->all())->toBe([true, false])
        ->and($order->items[1]->product_id)->toBeNull()->and((string) $order->items[1]->tax_amount)->toBe('0.0000');

    // Paying the invoice never moves the part again.
    app(OrderService::class)->confirmOrder($order);
    expect(screenStock())->toBe('2.000');
    tenancy()->initialize($this->tenant);
    expect((string) Inventory::query()->where('product_id', $this->screen->id)->value('reserved_quantity'))->toBe('0.000');

    $this->tenantJson('PATCH', "{$url}/status", ['status' => 'completed'], $this->staff)->assertOk();
    Notification::assertSentTo($this->ada, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'repair_job.ready_for_pickup');
    $this->tenantJson('PATCH', "{$url}/picked-up", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'picked_up');
    $this->tenantJson('GET', '/api/account/repair-jobs', [], $this->adaAuth)->assertOk()->assertJsonPath('data.0.job_number', 'RJ-'.str_pad((string) $job['id'], 6, '0', STR_PAD_LEFT));

    // A walk-in job without an estimate goes straight to repair; cancelling restocks its parts.
    $walkIn = $this->tenantJson('POST', '/api/admin/repair-jobs', ['customer_name' => 'Emeka', 'item_description' => 'Laptop hinge', 'warehouse_id' => $this->shop->id], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('PATCH', "/api/admin/repair-jobs/{$walkIn['id']}/status", ['status' => 'diagnosing'], $this->staff)->assertOk();
    $this->tenantJson('PATCH', "/api/admin/repair-jobs/{$walkIn['id']}/status", ['status' => 'in_repair'], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/admin/repair-jobs/{$walkIn['id']}/parts", ['product_id' => $this->screen->id, 'quantity' => 1], $this->staff)->assertCreated();
    expect(screenStock())->toBe('1.000');
    $this->tenantJson('PATCH', "/api/admin/repair-jobs/{$walkIn['id']}/status", ['status' => 'cancelled'], $this->staff)->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(screenStock())->toBe('2.000');

    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/repair?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['in_workshop' => 0, 'awaiting_approval' => 0, 'completed' => 1]);
});
