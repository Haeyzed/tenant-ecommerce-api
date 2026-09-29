<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Restaurant\Jobs\MarkTableReserved;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Services\TaxService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);

    tenancy()->initialize($this->tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    // The dining room is in Lagos: 7.5% VAT.
    app(WarehouseService::class)->ensureDefault();
    $this->kitchen = Warehouse::query()->firstOrFail();
    $this->kitchen->forceFill(['country_id' => 1])->save();
    app(TaxService::class)->createTaxRate(['name' => 'VAT', 'country_id' => 1, 'rate_percentage' => '7.5']);

    $this->jollof = Product::query()->create(['name' => 'Jollof rice', 'price' => '2000', 'is_active' => true]);
    $this->soup = Product::query()->create(['name' => 'Pepper soup', 'price' => '3000', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->kitchen, $this->jollof, null, '10', 'adjustment_in');
    app(InventoryService::class)->adjustStock($this->kitchen, $this->soup, null, '5', 'adjustment_in');

    $this->tenantJson('POST', '/api/admin/modules/pos/enable', [], $this->staff)->assertOk();
    $this->tenantJson('POST', '/api/admin/modules/restaurant/enable', [], $this->staff)->assertOk();
    $this->tenantJson('PUT', '/api/admin/pos/settings', ['enabled_payment_methods' => ['cash', 'bank_transfer']], $this->staff)->assertOk();
    $this->register = $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $this->kitchen->id, 'name' => 'Bar'], $this->staff)->assertCreated()->json('data');

    $floor = $this->tenantJson('POST', '/api/admin/restaurant/floors', ['name' => 'Main Hall', 'warehouse_id' => $this->kitchen->id], $this->staff)->assertCreated()->json('data');
    $this->table = $this->tenantJson('POST', '/api/admin/restaurant/tables', ['restaurant_floor_id' => $floor['id'], 'name' => 'Table 4', 'seats' => 4], $this->staff)
        ->assertCreated()->assertJsonPath('data.status', 'available')->json('data');
    $this->tenantJson('POST', '/api/admin/restaurant/tables', ['restaurant_floor_id' => $floor['id'], 'name' => 'Table 4', 'seats' => 2], $this->staff)->assertStatus(422);

    // Spice (pick one, required) and add-ons (any), both on the jollof.
    $this->spice = $this->tenantJson('POST', '/api/admin/restaurant/modifier-groups', ['name' => 'Spice', 'selection_type' => 'single', 'is_required' => true,
        'options' => [['name' => 'Mild'], ['name' => 'Hot', 'price_adjustment' => '200']]], $this->staff)->assertCreated()->json('data');
    $this->addOns = $this->tenantJson('POST', '/api/admin/restaurant/modifier-groups', ['name' => 'Add-ons', 'selection_type' => 'multiple',
        'options' => [['name' => 'Plantain', 'price_adjustment' => '500'], ['name' => 'Egg', 'price_adjustment' => '300']]], $this->staff)->assertCreated()->json('data');

    foreach ([$this->spice, $this->addOns] as $group) {
        $this->tenantJson('POST', "/api/admin/products/{$this->jollof->id}/modifier-groups/{$group['id']}", [], $this->staff)->assertOk();
    }
});

function dishStock(Product $product): array
{
    tenancy()->initialize(test()->tenant);
    $row = Inventory::query()->where('product_id', $product->id)->firstOrFail();

    return [(string) $row->quantity, (string) $row->reserved_quantity];
}

function tableStatus(): string
{
    tenancy()->initialize(test()->tenant);

    return RestaurantTable::query()->findOrFail(test()->table['id'])->status;
}

it('runs a table from the first round to the bill: modifiers, kitchen, settlement at the register', function (): void {
    [$mild, $hot] = array_column($this->spice['options'], 'id');
    [$plantain, $egg] = array_column($this->addOns['options'], 'id');
    $open = fn (array $lines) => $this->tenantJson('POST', "/api/admin/restaurant/tables/{$this->table['id']}/orders", ['lines' => $lines], $this->staff);

    // Table features follow the POS setting.
    $open([])->assertStatus(422)->assertJsonPath('meta.error_code', 'table_management_disabled');
    $this->tenantJson('PUT', '/api/admin/pos/settings', ['table_management_enabled' => true], $this->staff)->assertOk();

    $open([['product_id' => $this->jollof->id, 'quantity' => 2]])->assertStatus(422)->assertJsonPath('meta.error_code', 'modifier_required');
    $open([['product_id' => $this->jollof->id, 'quantity' => 2, 'modifier_option_ids' => [$mild, $hot]]])->assertStatus(422)->assertJsonPath('meta.error_code', 'modifier_invalid');

    // 2 × (2,000 + hot 200 + plantain 500 + egg 300) = 6,000 + 450 VAT.
    $order = $open([['product_id' => $this->jollof->id, 'quantity' => 2, 'modifier_option_ids' => [$hot, $plantain, $egg]]])->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.items.0.unit_price', '3000.0000')->assertJsonPath('data.total', '6450.0000')
        ->assertJsonPath('data.items.0.kitchen_status', 'pending')->assertJsonPath('data.items.0.modifiers.1.name', 'Add-ons: Plantain')->json('data');
    expect(dishStock($this->jollof))->toBe(['10.000', '2.000'])->and(tableStatus())->toBe('occupied');
    $open([])->assertStatus(409)->assertJsonPath('meta.error_code', 'table_occupied');

    // The mains: a second round, into the kitchen queue.
    $this->tenantJson('POST', "/api/admin/restaurant/table-orders/{$order['id']}/items", ['lines' => [['product_id' => $this->soup->id, 'quantity' => 1]]], $this->staff)
        ->assertOk()->assertJsonPath('data.total', '9675.0000')->assertJsonPath('data.items.1.modifiers', []);
    $this->tenantJson('GET', "/api/admin/restaurant/tables/{$this->table['id']}/current-order", [], $this->staff)->assertOk()->assertJsonPath('data.id', $order['id']);

    $tickets = $this->tenantJson('GET', '/api/admin/restaurant/kitchen/tickets', [], $this->staff)->assertOk()->json('data');
    expect($tickets)->toHaveCount(1)->and($tickets[0]['table']['name'])->toBe('Table 4')
        ->and($tickets[0]['items'][0]['modifiers'])->toBe(['Spice: Hot', 'Add-ons: Plantain', 'Add-ons: Egg']);
    $dish = $tickets[0]['items'][0]['id'];
    $this->tenantJson('PATCH', "/api/admin/restaurant/kitchen/order-items/{$dish}/status", ['status' => 'preparing'], $this->staff)->assertOk();
    $this->travel(12)->minutes();
    $this->tenantJson('PATCH', "/api/admin/restaurant/kitchen/order-items/{$dish}/status", ['status' => 'ready'], $this->staff)->assertOk()->assertJsonPath('data.kitchen_status', 'ready');
    $this->tenantJson('PATCH', "/api/admin/restaurant/kitchen/order-items/{$dish}/status", ['status' => 'preparing'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');
    $this->tenantJson('GET', '/api/admin/restaurant/kitchen/metrics', [], $this->staff)->assertOk()
        ->assertJsonPath('data.average_preparation_minutes', 12)->assertJsonPath('data.open_tickets', 1);

    // The bill: cash sessions are on, so a session must be open; the change is given back.
    $settle = fn (array $body) => $this->tenantJson('POST', "/api/admin/restaurant/table-orders/{$order['id']}/settle", ['register_id' => $this->register['id'], ...$body], $this->staff);
    $settle(['payments' => [['method' => 'cash', 'amount' => '10000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'session_required');
    $session = $this->tenantJson('POST', '/api/admin/pos/sessions', ['register_id' => $this->register['id'], 'opening_cash_float' => '0'], $this->staff)->assertCreated()->json('data');
    $settle(['payments' => [['method' => 'cash', 'amount' => '5000']]])->assertStatus(422)->assertJsonPath('meta.error_code', 'payment_insufficient');
    $settle(['quote_total' => '6450', 'payments' => [['method' => 'cash', 'amount' => '10000']]])->assertStatus(409)->assertJsonPath('meta.error_code', 'totals_changed');

    $settled = $settle(['payments' => [['method' => 'bank_transfer', 'amount' => '5000', 'reference' => 'TRF-1'], ['method' => 'cash', 'amount' => '5000']]])->assertOk()->json('data');
    expect($settled['order'])->toMatchArray(['status' => 'completed', 'payment_status' => 'paid', 'pos_session_id' => $session['id']])
        ->and($settled['receipt']['payments'][1])->toMatchArray(['method' => 'cash', 'amount_paid' => '4675.0000', 'change_given' => '325.0000'])
        ->and(dishStock($this->jollof))->toBe(['8.000', '0.000'])->and(dishStock($this->soup))->toBe(['4.000', '0.000'])
        ->and(tableStatus())->toBe('available');

    $this->tenantJson('POST', "/api/admin/restaurant/table-orders/{$order['id']}/items", ['lines' => [['product_id' => $this->soup->id, 'quantity' => 1]]], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'order_not_open');
    $this->tenantJson('POST', "/api/admin/restaurant/table-orders/{$order['id']}/void", ['reason' => 'x'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'table_order_closed');

    // One-pass counter sales take menu options too, snapshotted on the line.
    $sale = $this->tenantJson('POST', '/api/admin/pos/sales', ['register_id' => $this->register['id'], 'idempotency_key' => Str::uuid()->toString(),
        'lines' => [['product_id' => $this->jollof->id, 'quantity' => 1, 'modifier_option_ids' => [$mild, $egg]]], 'payments' => [['method' => 'cash', 'amount' => '2472.50']]], $this->staff)
        ->assertCreated()->assertJsonPath('data.sale.items.0.unit_price', '2300.0000')->assertJsonPath('data.sale.total', '2472.5000')->json('data.sale');
    tenancy()->initialize($this->tenant);
    expect(DB::connection('tenant')->table('order_item_modifiers')->where('order_item_id', $sale['items'][0]['id'])->pluck('name_snapshot')->all())->toBe(['Spice: Mild', 'Add-ons: Egg']);

    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/restaurant?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['occupied_tables' => 0, 'open_table_orders' => 0, 'average_preparation_time' => 720]);
});

it('holds the table before a reservation, seats the party and frees the table when the order is voided', function (): void {
    $this->tenantJson('PUT', '/api/admin/pos/settings', ['table_management_enabled' => true], $this->staff)->assertOk();
    $at = now()->toImmutable()->addHours(2)->startOfMinute();

    $reservation = $this->tenantJson('POST', '/api/admin/restaurant/reservations', ['restaurant_table_id' => $this->table['id'], 'customer_name' => 'Chidi',
        'customer_phone' => '+2348000000002', 'party_size' => 3, 'reservation_time' => $at->toIso8601String()], $this->staff)
        ->assertCreated()->assertJsonPath('data.status', 'confirmed')->json('data');
    // The hold job ran early (sync queue) and did nothing.
    expect(tableStatus())->toBe('available');

    // Moved by an hour: the old job no longer matches.
    $this->tenantJson('PATCH', "/api/admin/restaurant/reservations/{$reservation['id']}", ['reservation_time' => $at->addHour()->toIso8601String()], $this->staff)->assertOk();
    $this->travel(100)->minutes();
    (new MarkTableReserved((string) $this->tenant->getTenantKey(), $reservation['id'], $at->toIso8601String()))->handle();
    expect(tableStatus())->toBe('available');
    // Half an hour before the new time (+3h), the hold applies.
    $this->travel(55)->minutes();
    (new MarkTableReserved((string) $this->tenant->getTenantKey(), $reservation['id'], $at->addHour()->toIso8601String()))->handle();
    expect(tableStatus())->toBe('reserved');

    // Staff cannot mark it reserved or occupied by hand.
    $this->tenantJson('PATCH', "/api/admin/restaurant/tables/{$this->table['id']}/status", ['status' => 'occupied'], $this->staff)->assertStatus(422);

    $seated = $this->tenantJson('PATCH', "/api/admin/restaurant/reservations/{$reservation['id']}/seat", [], $this->staff)->assertOk()
        ->assertJsonPath('data.reservation.status', 'seated')->json('data');
    expect(tableStatus())->toBe('occupied');
    $this->tenantJson('PATCH', "/api/admin/restaurant/reservations/{$reservation['id']}/no-show", [], $this->staff)->assertStatus(422);
    $this->tenantJson('PATCH', "/api/admin/restaurant/tables/{$this->table['id']}/status", ['status' => 'cleaning'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'table_has_open_order');
    $this->tenantJson('DELETE', "/api/admin/restaurant/tables/{$this->table['id']}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'table_has_open_order');

    $this->tenantJson('POST', "/api/admin/restaurant/table-orders/{$seated['order_id']}/items", ['lines' => [['product_id' => $this->soup->id, 'quantity' => 2]]], $this->staff)
        ->assertOk()->assertJsonPath('data.customer_name', 'Chidi');
    expect(dishStock($this->soup))->toBe(['5.000', '2.000']);
    $this->tenantJson('POST', "/api/admin/restaurant/table-orders/{$seated['order_id']}/settle", ['register_id' => $this->register['id'], 'payments' => []], $this->staff)
        ->assertStatus(422);

    // Walked out before ordering mains: voided, stock released, table free.
    $this->tenantJson('POST', "/api/admin/restaurant/table-orders/{$seated['order_id']}/void", ['reason' => 'Party left'], $this->staff)->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(dishStock($this->soup))->toBe(['5.000', '0.000'])->and(tableStatus())->toBe('available');
    $this->tenantJson('GET', '/api/admin/restaurant/reservations?status=cancelled', [], $this->staff)->assertOk()->assertJsonPath('data.0.id', $reservation['id']);

    $this->tenantJson('PATCH', "/api/admin/restaurant/tables/{$this->table['id']}/status", ['status' => 'cleaning'], $this->staff)->assertOk()->assertJsonPath('data.status', 'cleaning');
    $this->tenantJson('GET', '/api/admin/restaurant/tables?status=cleaning', [], $this->staff)->assertOk()->assertJsonPath('data.0.current_order', null);
});
