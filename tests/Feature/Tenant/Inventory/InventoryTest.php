<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBundleItem;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Events\StockReplenished;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehousePricingService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
});

function inventoryWarehouse(string $name): Warehouse
{
    $warehouse = new Warehouse(['name' => $name, 'code' => strtoupper($name)]);
    $warehouse->is_active = true;
    $warehouse->save();

    return $warehouse;
}

function inventoryProduct(array $attributes = []): Product
{
    return Product::query()->create(array_merge(['name' => 'Mug', 'price' => '20', 'cost_price' => '8', 'product_type' => 'simple', 'is_active' => true], $attributes));
}

function stockOf(Product $product, Warehouse $warehouse, ?ProductVariant $variant = null): array
{
    $row = Inventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->where('variant_key', $variant?->id ?? 0)->first();

    return [(string) ($row?->quantity ?? '0.000'), (string) ($row?->reserved_quantity ?? '0.000')];
}

it('seeds one default warehouse and enforces max_warehouses on create and activate', function (): void {
    app(WarehouseService::class)->ensureDefault();
    expect(Warehouse::query()->count())->toBe(1);

    // basic: one active warehouse.
    $this->tenantJson('POST', '/api/admin/warehouses', ['name' => 'Annex'], $this->auth)->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');

    tenancy()->initialize($this->tenant);
    PlanLimit::query()->where('limit_key', 'max_warehouses')->update(['limit_value' => 2]);
    app(PlanLimitService::class)->flush($this->tenant);

    $annex = $this->tenantJson('POST', '/api/admin/warehouses', ['name' => 'Annex', 'code' => 'annex-1'], $this->auth)
        ->assertCreated()->assertJsonPath('data.code', 'ANNEX-1')->json('data.id');
    $this->tenantJson('PATCH', "/api/admin/warehouses/{$this->main->id}", ['code' => 'ANNEX-1'], $this->auth)->assertStatus(422)->assertJsonValidationErrors('code');

    $this->tenantJson('POST', "/api/admin/warehouses/{$annex}/deactivate", [], $this->auth)->assertOk()->assertJsonPath('data.is_active', false);
    $this->tenantJson('POST', "/api/admin/warehouses/{$this->main->id}/deactivate", [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'last_active_warehouse');

    $this->tenantJson('POST', '/api/admin/warehouses', ['name' => 'Third'], $this->auth)->assertCreated();
    $this->tenantJson('POST', "/api/admin/warehouses/{$annex}/activate", [], $this->auth)->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');

    // A warehouse with stock history cannot be deleted; an unused one can.
    tenancy()->initialize($this->tenant);
    app(InventoryService::class)->adjustStock($this->main, inventoryProduct(), null, '3', 'adjustment_in');
    $this->tenantJson('DELETE', "/api/admin/warehouses/{$this->main->id}", [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'warehouse_in_use')->assertJsonPath('meta.details.used_by', ['stock_history']);
    $this->tenantJson('DELETE', "/api/admin/warehouses/{$annex}", [], $this->auth)->assertOk();
});

it('submits adjustments all-or-nothing with cost snapshots and keeps them immutable', function (): void {
    $mug = inventoryProduct();
    $plate = inventoryProduct(['name' => 'Plate', 'cost_price' => '3']);
    $bundle = inventoryProduct(['name' => 'Set', 'product_type' => 'bundle']);

    $id = $this->tenantJson('POST', '/api/admin/stock-adjustments', ['warehouse_id' => $this->main->id, 'notes' => 'Stock take'], $this->auth)
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->tenantJson('POST', "/api/admin/stock-adjustments/{$id}/items", ['product_id' => $bundle->id, 'action' => 'addition', 'quantity' => 1], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'product_not_stock_tracked');
    $this->tenantJson('POST', "/api/admin/stock-adjustments/{$id}/items", ['product_id' => $mug->id, 'action' => 'addition', 'quantity' => 0], $this->auth)->assertStatus(422);

    $this->tenantJson('POST', "/api/admin/stock-adjustments/{$id}/items", ['product_id' => $mug->id, 'action' => 'addition', 'quantity' => 10], $this->auth)
        ->assertCreated()->assertJsonPath('data.unit_cost_snapshot', '8.0000');
    $line = $this->tenantJson('POST', "/api/admin/stock-adjustments/{$id}/items", ['product_id' => $plate->id, 'action' => 'subtraction', 'quantity' => 2], $this->auth)
        ->assertCreated()->json('data.id');

    // Plate has no stock: the whole batch fails and nothing moves.
    $this->tenantJson('PATCH', "/api/admin/stock-adjustments/{$id}/submit", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'insufficient_stock');
    tenancy()->initialize($this->tenant);
    expect(stockOf($mug, $this->main))->toBe(['0.000', '0.000'])->and(InventoryMovement::query()->count())->toBe(0);

    $this->tenantJson('PATCH', "/api/admin/stock-adjustments/{$id}/items/{$line}", ['action' => 'addition'], $this->auth)->assertOk();
    $this->tenantJson('PATCH', "/api/admin/stock-adjustments/{$id}/submit", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'submitted');

    tenancy()->initialize($this->tenant);
    expect(stockOf($mug, $this->main))->toBe(['10.000', '0.000'])
        ->and(stockOf($plate, $this->main))->toBe(['2.000', '0.000']);

    $movement = InventoryMovement::query()->where('product_id', $mug->id)->firstOrFail();
    expect($movement->movement_type)->toBe('adjustment_in')
        ->and((string) $movement->unit_cost_snapshot)->toBe('8.0000')
        ->and($movement->reference_type)->toBe('stock_adjustment')
        ->and($movement->user_id)->toBe($this->owner->id)
        ->and($movement->reason)->toBe('Stock take');

    $this->tenantJson('POST', "/api/admin/stock-adjustments/{$id}/items", ['product_id' => $mug->id, 'action' => 'addition', 'quantity' => 1], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'adjustment_submitted');
    $this->tenantJson('GET', "/api/admin/inventory/movements?product_id={$mug->id}", [], $this->auth)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.quantity_after', '10.000');
    $this->tenantJson('GET', "/api/admin/warehouses/{$this->main->id}/product-lookup?search=Mug", [], $this->auth)->assertOk()
        ->assertJsonPath('data.0.quantity', '10.000')->assertJsonPath('data.0.cost_price', '8.0000');
});

it('reserves, deducts and releases stock, expanding bundles and never going negative', function (): void {
    $inventory = app(InventoryService::class);
    $mug = inventoryProduct();
    $ebook = inventoryProduct(['name' => 'E-book', 'product_type' => 'digital']);
    $set = inventoryProduct(['name' => 'Set', 'product_type' => 'bundle']);
    ProductBundleItem::query()->create(['bundle_product_id' => $set->id, 'child_product_id' => $mug->id, 'quantity' => '2']);

    $inventory->adjustStock($this->main, $mug, null, '10', 'adjustment_in');
    $inventory->reserveStock($this->main, $mug, null, '4', $mug);
    expect(stockOf($mug, $this->main))->toBe(['10.000', '4.000'])
        ->and($inventory->getAvailableStock($mug))->toBe('6.000')
        ->and($inventory->getAvailableStock($set))->toBe('3.000')
        ->and($inventory->getAvailableStock($ebook))->toBeNull();

    expect(fn () => $inventory->reserveStock($this->main, $mug, null, '7', $mug))->toThrow(InsufficientStockException::class);
    expect(fn () => $inventory->adjustStock($this->main, $mug, null, '-7', 'adjustment_out'))->toThrow(InsufficientStockException::class);

    // A bundle reserves its children; a digital line skips inventory.
    $inventory->reserveStock($this->main, $set, null, '3', $set);
    $inventory->reserveStock($this->main, $ebook, null, '5', $ebook);
    expect(stockOf($mug, $this->main))->toBe(['10.000', '10.000']);

    $inventory->deductStock($this->main, $mug, null, '4', $mug);
    $inventory->releaseReservedStock($this->main, $set, null, '3', $set);
    expect(stockOf($mug, $this->main))->toBe(['6.000', '0.000']);

    expect(fn () => $inventory->releaseReservedStock($this->main, $mug, null, '1', $mug))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('reservation_not_found'));

    $inventory->deductStock($this->main, $mug, null, '1', $mug, fromReservation: false);
    expect(stockOf($mug, $this->main))->toBe(['5.000', '0.000'])
        ->and(InventoryMovement::query()->where('product_id', $mug->id)->pluck('movement_type')->all())
        ->toBe(['adjustment_in', 'reserve', 'reserve', 'deduct', 'release', 'deduct']);

    // The database refuses a negative balance even outside the service.
    expect(fn () => DB::connection('tenant')->table('inventory')->where('product_id', $mug->id)->update(['reserved_quantity' => 99]))
        ->toThrow(QueryException::class);
    expect(fn () => InventoryMovement::query()->firstOrFail()->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class);
});

it('keeps the balance equal to the ledger and reports drift through inventory:verify', function (): void {
    $inventory = app(InventoryService::class);
    $mug = inventoryProduct();
    $row = $inventory->adjustStock($this->main, $mug, null, '8', 'adjustment_in');
    $inventory->reserveStock($this->main, $mug, null, '3', $mug);

    expect($inventory->drift())->toHaveCount(0);
    $this->artisan('inventory:verify', ['tenant' => $this->tenant->id])->assertSuccessful();

    DB::connection('tenant')->table('inventory')->where('id', $row->id)->update(['quantity' => 9]);
    expect($inventory->drift())->toHaveCount(1);
    $this->artisan('inventory:verify', ['tenant' => $this->tenant->id])->assertFailed();

    $repaired = $inventory->rebuildBalance($row);
    expect((string) $repaired->quantity)->toBe('8.000')->and((string) $repaired->reserved_quantity)->toBe('3.000')
        ->and($inventory->drift())->toHaveCount(0);
});

it('dispatches, partially receives and cancels transfers', function (): void {
    PlanLimit::query()->where('limit_key', 'max_warehouses')->update(['limit_value' => 3]);
    app(PlanLimitService::class)->flush($this->tenant);
    $annex = inventoryWarehouse('Annex');
    $mug = inventoryProduct();
    app(InventoryService::class)->adjustStock($this->main, $mug, null, '10', 'adjustment_in');

    $this->tenantJson('POST', '/api/admin/stock-transfers', ['from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $this->main->id, 'items' => [['product_id' => $mug->id, 'quantity' => 1]]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('to_warehouse_id');

    $big = $this->tenantJson('POST', '/api/admin/stock-transfers', ['from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $annex->id, 'items' => [['product_id' => $mug->id, 'quantity' => 11]]], $this->auth)
        ->assertCreated()->json('data.id');
    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$big}/dispatch", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'insufficient_stock');
    $this->tenantJson('GET', "/api/admin/stock-transfers/{$big}", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'draft');

    $transfer = $this->tenantJson('POST', '/api/admin/stock-transfers', ['from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $annex->id, 'items' => [['product_id' => $mug->id, 'quantity' => 5]]], $this->auth)
        ->assertCreated()->json('data');
    $item = $transfer['items'][0]['id'];

    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$transfer['id']}/dispatch", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'in_transit');
    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$transfer['id']}", ['notes' => 'late'], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'transfer_not_draft');

    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$transfer['id']}/receive", ['items' => [['item_id' => $item, 'quantity' => 6]]], $this->auth)->assertStatus(422);
    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$transfer['id']}/receive", ['items' => [['item_id' => $item, 'quantity' => 2]]], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'in_transit')->assertJsonPath('data.items.0.remaining', '3.000');

    tenancy()->initialize($this->tenant);
    expect(stockOf($mug, $this->main))->toBe(['5.000', '0.000'])->and(stockOf($mug, $annex))->toBe(['2.000', '0.000']);

    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$transfer['id']}/cancel", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'cancelled');
    tenancy()->initialize($this->tenant);
    expect(stockOf($mug, $this->main))->toBe(['8.000', '0.000'])->and(stockOf($mug, $annex))->toBe(['2.000', '0.000']);

    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$transfer['id']}/receive", ['items' => [['item_id' => $item, 'quantity' => 1]]], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');

    $full = $this->tenantJson('POST', '/api/admin/stock-transfers', ['from_warehouse_id' => $annex->id, 'to_warehouse_id' => $this->main->id, 'items' => [['product_id' => $mug->id, 'quantity' => 2]]], $this->auth)->json('data');
    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$full['id']}/dispatch", [], $this->auth)->assertOk();
    $this->tenantJson('PATCH', "/api/admin/stock-transfers/{$full['id']}/receive", ['items' => [['item_id' => $full['items'][0]['id'], 'quantity' => 2]]], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'received');
});

it('drives storefront availability and fulfilment warehouse selection from active warehouses', function (): void {
    PlanLimit::query()->where('limit_key', 'max_warehouses')->update(['limit_value' => 3]);
    app(PlanLimitService::class)->flush($this->tenant);
    $annex = inventoryWarehouse('Annex');
    $inventory = app(InventoryService::class);

    $mug = inventoryProduct(['name' => 'Mug']);
    $hoodie = inventoryProduct(['name' => 'Hoodie', 'product_type' => 'variable']);
    $small = ProductVariant::query()->forceCreate(['product_id' => $hoodie->id, 'sku' => 'HD-S', 'is_active' => true]);
    $set = inventoryProduct(['name' => 'Set', 'product_type' => 'bundle']);
    ProductBundleItem::query()->create(['bundle_product_id' => $set->id, 'child_product_id' => $mug->id, 'quantity' => '2']);
    inventoryProduct(['name' => 'Plate']);

    $inventory->adjustStock($this->main, $mug, null, '1', 'adjustment_in');
    $inventory->adjustStock($annex, $mug, null, '5', 'adjustment_in');
    $inventory->adjustStock($annex, $hoodie, $small, '2', 'adjustment_in');

    $names = fn (string $query): array => array_column($this->tenantJson('GET', '/api/products'.$query)->assertOk()->json('data'), 'name');
    expect($names('?in_stock=1'))->toEqualCanonicalizing(['Mug', 'Hoodie', 'Set']);
    $this->tenantJson('GET', "/api/products/{$mug->slug}")->assertOk()->assertJsonPath('data.in_stock', true);

    tenancy()->initialize($this->tenant);
    expect($inventory->selectFulfillmentWarehouse($mug, null, '1')?->id)->toBe($this->main->id)
        ->and($inventory->selectFulfillmentWarehouse($mug, null, '2')?->id)->toBe($annex->id)
        ->and($inventory->selectFulfillmentWarehouse($mug, null, '6'))->toBeNull()
        ->and($inventory->selectFulfillmentWarehouse($set, null, '2')?->id)->toBe($annex->id);

    // Stock in an inactive warehouse is not sellable; an inactive variant neither.
    $annex->forceFill(['is_active' => false])->save();
    expect($names('?in_stock=1'))->toBe(['Mug'])
        ->and($inventory->selectFulfillmentWarehouse($mug, null, '2'))->toBeNull();

    $this->tenantJson('GET', "/api/admin/products/{$hoodie->id}/inventory", [], $this->auth)->assertOk()
        ->assertJsonPath('data.variants.0.sku', 'HD-S')->assertJsonPath('data.variants.0.total_quantity', '2.000');
});

it('alerts on low and out-of-stock crossings and lists stock levels', function (): void {
    Event::fake([StockReplenished::class]);
    $inventory = app(InventoryService::class);
    $mug = inventoryProduct(['sku' => 'MUG-1']);
    inventoryProduct(['name' => 'Plate']);

    $inventory->adjustStock($this->main, $mug, null, '10', 'adjustment_in');
    Notification::assertNothingSent();
    Event::assertDispatched(StockReplenished::class, fn (StockReplenished $e): bool => $e->productId === $mug->id && $e->variantId === null);

    $inventory->reserveStock($this->main, $mug, null, '6', $mug);
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn ($n): bool => $n->key === 'inventory.low_stock');

    $this->tenantJson('GET', '/api/admin/inventory/low-stock', [], $this->auth)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'MUG-1')->assertJsonPath('data.0.available', '4.000');
    $this->tenantJson('GET', '/api/admin/inventory/out-of-stock', [], $this->auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_name', 'Plate');
    $this->tenantJson('GET', "/api/admin/inventory?warehouse_id={$this->main->id}&search=MUG-1", [], $this->auth)->assertOk()->assertJsonPath('data.0.quantity', '10.000');

    tenancy()->initialize($this->tenant);
    $inventory->deductStock($this->main, $mug, null, '6', $mug);
    $inventory->adjustStock($this->main, $mug, null, '-4', 'adjustment_out');
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn ($n): bool => $n->key === 'inventory.out_of_stock');
});

it('narrows warehouse-scoped staff to their assigned warehouses', function (): void {
    PlanLimit::query()->where('limit_key', 'max_warehouses')->update(['limit_value' => 3]);
    app(PlanLimitService::class)->flush($this->tenant);
    $annex = inventoryWarehouse('Annex');
    $mug = inventoryProduct();
    app(InventoryService::class)->adjustStock($this->main, $mug, null, '3', 'adjustment_in');
    app(InventoryService::class)->adjustStock($annex, $mug, null, '4', 'adjustment_in');
    app(TenantSettingsService::class)->set('staff_data_access_scope', 'warehouse');

    $keeper = User::query()->create(['name' => 'Keeper', 'email' => 'keeper@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $keeper->assignRole('warehouse');
    $keeperAuth = ['Authorization' => 'Bearer '.$keeper->createToken('t', ['staff'])->plainTextToken];

    $this->tenantJson('PUT', "/api/admin/users/{$keeper->id}/warehouses", ['warehouse_ids' => [$annex->id, 999999]], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'warehouse_unknown');
    $this->tenantJson('PUT', "/api/admin/users/{$keeper->id}/warehouses", ['warehouse_ids' => [$annex->id]], $this->auth)
        ->assertOk()->assertJsonPath('data.warehouse_ids', [$annex->id]);

    expect(array_column($this->tenantJson('GET', '/api/admin/warehouses', [], $keeperAuth)->assertOk()->json('data'), 'id'))->toBe([$annex->id]);
    $this->tenantJson('GET', "/api/admin/warehouses/{$this->main->id}/inventory", [], $keeperAuth)->assertNotFound();
    $this->tenantJson('GET', "/api/admin/warehouses/{$annex->id}/inventory", [], $keeperAuth)->assertOk()->assertJsonPath('data.0.quantity', '4.000');
    $this->tenantJson('GET', '/api/admin/inventory/movements', [], $keeperAuth)->assertOk()->assertJsonCount(1, 'data');
    $this->tenantJson('GET', '/api/admin/inventory/low-stock', [], $keeperAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'warehouse_required');
    $this->tenantJson('POST', '/api/admin/stock-transfers', ['from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $annex->id, 'items' => [['product_id' => $mug->id, 'quantity' => 1]]], $keeperAuth)->assertNotFound();
    $this->tenantJson('POST', '/api/admin/stock-transfers', ['from_warehouse_id' => $annex->id, 'to_warehouse_id' => $this->main->id, 'items' => [['product_id' => $mug->id, 'quantity' => 1]]], $keeperAuth)->assertCreated();

    // Owners are never narrowed.
    $this->tenantJson('GET', '/api/admin/warehouses', [], $this->auth)->assertOk()->assertJsonCount(2, 'data');
});

it('prices products per warehouse only when the product opts in', function (): void {
    $pricing = app(WarehousePricingService::class);
    $hoodie = inventoryProduct(['name' => 'Hoodie', 'product_type' => 'variable', 'price' => '30']);
    $small = ProductVariant::query()->forceCreate(['product_id' => $hoodie->id, 'sku' => 'HD-S', 'is_active' => true]);
    $large = ProductVariant::query()->forceCreate(['product_id' => $hoodie->id, 'sku' => 'HD-L', 'is_active' => true]);

    $this->tenantJson('POST', "/api/admin/products/{$hoodie->id}/warehouse-prices", ['warehouse_id' => $this->main->id, 'price' => '35'], $this->auth)->assertCreated();
    $this->tenantJson('POST', "/api/admin/products/{$hoodie->id}/warehouse-prices", ['warehouse_id' => $this->main->id, 'price' => '36'], $this->auth)
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'warehouse_price_exists');
    $this->tenantJson('POST', "/api/admin/products/{$hoodie->id}/warehouse-prices", ['warehouse_id' => $this->main->id, 'product_variant_id' => $large->id, 'price' => '40'], $this->auth)->assertCreated();
    $this->tenantJson('POST', "/api/admin/products/{$hoodie->id}/warehouse-prices", ['warehouse_id' => $this->main->id, 'price' => '-1'], $this->auth)->assertStatus(422);

    tenancy()->initialize($this->tenant);
    expect($pricing->getPriceForWarehouse($hoodie->refresh(), $this->main, $large))->toBeNull();

    $this->tenantJson('PATCH', "/api/admin/products/{$hoodie->id}", ['has_warehouse_pricing' => true], $this->auth)->assertOk()->assertJsonPath('data.has_warehouse_pricing', true);
    tenancy()->initialize($this->tenant);
    $hoodie->refresh();
    expect($pricing->getPriceForWarehouse($hoodie, $this->main, $large)['price'])->toBe('40.0000')
        ->and($pricing->getPriceForWarehouse($hoodie, $this->main, $small)['price'])->toBe('35.0000');

    $this->tenantJson('GET', "/api/admin/products/{$hoodie->id}/warehouse-prices", [], $this->auth)->assertOk()->assertJsonCount(2, 'data');
});
