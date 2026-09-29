<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;

beforeEach(function (): void {
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->bread = Product::query()->create(['name' => 'Bread', 'sku' => 'BREAD', 'price' => '4', 'is_active' => true]);
    $this->flour = Product::query()->create(['name' => 'Flour (kg)', 'sku' => 'FLOUR', 'price' => '3', 'cost_price' => '2', 'is_active' => true]);
    $this->yeast = Product::query()->create(['name' => 'Yeast', 'sku' => 'YEAST', 'price' => '6', 'cost_price' => '5', 'is_active' => true]);
    $this->ebook = Product::query()->create(['name' => 'Recipe book', 'price' => '9', 'product_type' => 'digital', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->flour, null, '8', 'adjustment_in');
    app(InventoryService::class)->adjustStock($this->main, $this->yeast, null, '5', 'adjustment_in');

    $this->tenantJson('POST', '/api/admin/modules/manufacturing/enable', [], $this->staff)->assertOk();
});

function stockRow(Product $product): Inventory
{
    tenancy()->initialize(test()->tenant);

    return Inventory::query()->where('product_id', $product->id)->where('warehouse_id', test()->main->id)->firstOrFail();
}

it('rolls a recipe\'s cost up and turns reserved components into finished goods, all or nothing', function (): void {
    $items = [['component_product_id' => $this->flour->id, 'quantity_required' => 5], ['component_product_id' => $this->yeast->id, 'quantity_required' => 1]];
    $this->tenantJson('POST', "/api/admin/products/{$this->bread->id}/bill-of-materials", ['name' => 'X', 'items' => [...$items, ['component_product_id' => $this->ebook->id, 'quantity_required' => 1]]], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'bom_component_invalid');
    $this->tenantJson('POST', "/api/admin/products/{$this->bread->id}/bill-of-materials", ['name' => 'X', 'items' => [...$items, $items[0]]], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'bom_component_invalid');

    // 10 loaves from 5 kg flour (2 each) and 1 yeast (5): 1.50 a loaf. The first recipe is the default.
    $bom = $this->tenantJson('POST', "/api/admin/products/{$this->bread->id}/bill-of-materials", ['name' => 'Standard loaf', 'yield_quantity' => 10, 'items' => $items], $this->staff)
        ->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.calculated_unit_cost', '1.5000')->json('data');
    tenancy()->initialize($this->tenant);
    expect((string) $this->bread->fresh()->cost_price)->toBe('1.5000');

    // 20 loaves need 10 kg flour and 2 yeast; only 8 kg is in stock, so it cannot start.
    $order = $this->tenantJson('POST', '/api/admin/work-orders', ['bill_of_material_id' => $bom['id'], 'warehouse_id' => $this->main->id, 'quantity_to_produce' => 20], $this->staff)
        ->assertCreated()->assertJsonPath('data.work_order_number', 'WO-000001')->assertJsonPath('data.materials.0.quantity_required', '10.000')
        ->assertJsonPath('data.materials.1.quantity_required', '2.000')->json('data');
    $this->tenantJson('PATCH', "/api/admin/work-orders/{$order['id']}/start", [], $this->staff)->assertStatus(409)
        ->assertJsonPath('meta.error_code', 'insufficient_components')->assertJsonPath('meta.details.product_id', $this->flour->id);
    expect((string) stockRow($this->yeast)->reserved_quantity)->toBe('0.000');

    tenancy()->initialize($this->tenant);
    app(InventoryService::class)->adjustStock($this->main, $this->flour, null, '5', 'adjustment_in');
    $this->tenantJson('PATCH', "/api/admin/work-orders/{$order['id']}/start", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'in_progress');
    expect((string) stockRow($this->flour)->reserved_quantity)->toBe('10.000');

    // Flour gets dearer: the next completion rolls the new cost up.
    tenancy()->initialize($this->tenant);
    $this->flour->forceFill(['cost_price' => '3'])->save();
    $this->tenantJson('PATCH', "/api/admin/work-orders/{$order['id']}/complete", [], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.materials.0.quantity_consumed', '10.000');
    expect([(string) stockRow($this->flour)->quantity, (string) stockRow($this->flour)->reserved_quantity])->toBe(['3.000', '0.000'])
        ->and((string) stockRow($this->yeast)->quantity)->toBe('3.000')
        ->and((string) stockRow($this->bread)->quantity)->toBe('20.000');
    tenancy()->initialize($this->tenant);
    $output = InventoryMovement::query()->where('product_id', $this->bread->id)->where('movement_type', 'work_order_output')->firstOrFail();
    expect((string) $output->unit_cost_snapshot)->toBe('2.0000')->and((string) $this->bread->fresh()->cost_price)->toBe('2.0000');
    $this->tenantJson('PATCH', "/api/admin/work-orders/{$order['id']}/cancel", [], $this->staff)->assertStatus(422);
    $this->tenantJson('DELETE', "/api/admin/bill-of-materials/{$bom['id']}", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'bom_in_use');

    // A second run is cancelled mid-way: its reservations go back.
    $second = $this->tenantJson('POST', '/api/admin/work-orders', ['bill_of_material_id' => $bom['id'], 'warehouse_id' => $this->main->id, 'quantity_to_produce' => 5], $this->staff)->assertCreated()->json('data');
    $this->tenantJson('PATCH', "/api/admin/work-orders/{$second['id']}/start", [], $this->staff)->assertOk();
    expect((string) stockRow($this->flour)->reserved_quantity)->toBe('2.500');
    $this->tenantJson('PATCH', "/api/admin/work-orders/{$second['id']}/cancel", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect((string) stockRow($this->flour)->reserved_quantity)->toBe('0.000');

    // An alternate recipe made the default takes over the costing.
    $alt = $this->tenantJson('POST', "/api/admin/products/{$this->bread->id}/bill-of-materials", ['name' => 'Lean loaf', 'yield_quantity' => 10, 'items' => [$items[0]]], $this->staff)
        ->assertCreated()->assertJsonPath('data.is_default', false)->json('data');
    $this->tenantJson('PATCH', "/api/admin/bill-of-materials/{$alt['id']}/set-default", [], $this->staff)->assertOk()->assertJsonPath('data.is_default', true);
    $this->tenantJson('GET', "/api/admin/products/{$this->bread->id}/bill-of-materials", [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.id', $alt['id'])->assertJsonPath('data.1.is_default', false);
    tenancy()->initialize($this->tenant);
    expect((string) $this->bread->fresh()->cost_price)->toBe('1.5000');

    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/manufacturing?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['planned' => 0, 'in_progress' => 0, 'completed' => 1]);
});
