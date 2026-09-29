<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Exports\Jobs\GenerateExport;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Services\DataExportService;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    tenancy()->initialize($this->tenant);

    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    $this->clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->clerk->assignRole('staff');
    $this->clerkAuth = ['Authorization' => 'Bearer '.$this->clerk->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
});

afterEach(function (): void {
    foreach (glob(base_path('storage/tenants/test-tenant-a/app/*/{customers,products}-*.*'), GLOB_BRACE) ?: [] as $file) {
        File::delete($file);
        @rmdir(dirname($file));
    }
});

/**
 * Runs the queued export inline and returns its stored file path.
 *
 * @param  array<string, mixed>  $parameters
 */
function runExport(string $type, string $format, array $parameters = []): DataExport
{
    tenancy()->initialize(test()->tenant);
    $export = app(DataExportService::class)->request($type, $parameters, $format, test()->owner);
    (new GenerateExport($export->id))->handle(app(ExportRegistry::class), app(NotificationDispatchService::class));

    return $export->refresh();
}

it('exports customer and product lists with their list filters, as CSV and as formula-safe XLSX', function (): void {
    Customer::query()->create(['name' => '=cmd|calc', 'email' => 'ada@example.test', 'is_active' => true]);
    Customer::query()->create(['name' => 'Bola', 'email' => 'bola@example.test', 'is_active' => false]);
    $shoe = Product::query()->create(['name' => 'Runner', 'sku' => '00123', 'price' => '40000', 'is_active' => true]);
    Product::query()->create(['name' => 'Old', 'sku' => 'OLD', 'price' => '5', 'is_active' => false]);
    app(InventoryService::class)->adjustStock($this->main, $shoe, null, '7', 'adjustment_in');

    // The list's is_active filter applies to the export.
    $csv = runExport('customers', 'csv', ['is_active' => true]);
    $content = (string) file_get_contents($csv->getFirstMediaPath('file'));
    expect($csv->status)->toBe('completed')->and($csv->row_count)->toBe(1)
        ->and($content)->toContain("'=cmd|calc")->not->toContain('bola@');

    // XLSX: text stays text (never a formula), numbers are numbers, leading zeros kept.
    $xlsx = runExport('products', 'xlsx', ['is_active' => true]);
    $sheet = IOFactory::load($xlsx->getFirstMediaPath('file'))->getActiveSheet();
    expect($xlsx->row_count)->toBe(1)->and($sheet->getCell('A1')->getValue())->toBe('ID')
        ->and($sheet->getCell('B2')->getValue())->toBe('00123')->and($sheet->getCell('E2')->getValue())->toBe(40000.0)
        ->and($sheet->getCell('K2')->getValue())->toBe(7.0);

    $xlsxCustomers = runExport('customers', 'xlsx');
    $cell = IOFactory::load($xlsxCustomers->getFirstMediaPath('file'))->getActiveSheet()->getCell('B2');
    expect($cell->getValue())->toBe('=cmd|calc')->and($cell->getDataType())->toBe('s');

    // The export needs the list's permission.
    $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'customers', 'format' => 'csv'], $this->clerkAuth)->assertForbidden();
    $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'orders', 'format' => 'xlsx', 'parameters' => ['status' => 'nope']], $this->auth)->assertStatus(422);
    $this->tenantJson('POST', '/api/admin/exports', ['export_type' => 'orders', 'format' => 'xlsx'], $this->auth)->assertStatus(202);
});

it('runs bounded bulk actions item by item, with per-item results and one audit summary', function (): void {
    $group = CustomerGroup::query()->create(['name' => 'Wholesale']);
    $ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'is_active' => false]);
    $gone = Customer::query()->create(['name' => 'Gone', 'email' => 'gone@example.test', 'is_active' => false]);
    $gone->forceFill(['anonymized_at' => now()])->save();

    $result = $this->tenantJson('POST', '/api/admin/customers/bulk', ['action' => 'activate', 'ids' => [$ada->id, $gone->id, 999999]], $this->auth)
        ->assertOk()->assertJsonPath('data.succeeded', 1)->assertJsonPath('data.failed', 2)->json('data');
    expect(collect($result['results'])->pluck('status', 'id')->all())->toBe([$ada->id => 'ok', $gone->id => 'error', 999999 => 'error'])
        ->and(collect($result['results'])->firstWhere('id', 999999)['error'])->toBe('not_found');

    $this->tenantJson('POST', '/api/admin/customers/bulk', ['action' => 'assign_group', 'ids' => [$ada->id]], $this->auth)->assertStatus(422);
    $this->tenantJson('POST', '/api/admin/customers/bulk', ['action' => 'assign_group', 'ids' => [$ada->id], 'customer_group_id' => $group->id], $this->auth)->assertOk();
    $this->tenantJson('POST', '/api/admin/customers/bulk', ['action' => 'activate', 'ids' => range(1, 101)], $this->auth)->assertStatus(422);
    $this->tenantJson('POST', '/api/admin/customers/bulk', ['action' => 'activate', 'ids' => [$ada->id]], $this->clerkAuth)->assertForbidden();
    $this->tenantJson('POST', "/api/admin/customers/{$ada->id}/deactivate", [], $this->auth)->assertOk();
    $this->tenantJson('POST', "/api/admin/customers/{$ada->id}/activate", [], $this->auth)->assertOk()->assertJsonPath('data.is_active', true);

    tenancy()->initialize($this->tenant);
    expect($ada->fresh()->customer_group_id)->toBe($group->id);
    $summary = Activity::query()->where('log_name', 'bulk_actions')->oldest('id')->firstOrFail();
    expect($summary->properties['operation_id'])->toBe($result['operation_id'])->and($summary->properties['succeeded_ids'])->toBe([$ada->id])
        ->and($summary->causer_id)->toBe($this->owner->id);

    // Categories and orders: invalid moves fail per item without stopping the rest.
    $cat = Category::query()->create(['name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
    $this->tenantJson('POST', '/api/admin/categories/bulk', ['action' => 'deactivate', 'ids' => [$cat->id]], $this->auth)->assertOk()->assertJsonPath('data.succeeded', 1);
    expect($cat->fresh()->is_active)->toBeFalse();
    $this->tenantJson('POST', '/api/admin/orders/bulk', ['action' => 'delivered', 'ids' => [424242]], $this->auth)->assertOk()->assertJsonPath('data.results.0.error', 'not_found');
    $this->tenantJson('POST', '/api/admin/orders/bulk', ['action' => 'cancelled', 'ids' => [1]], $this->auth)->assertStatus(422);
});
