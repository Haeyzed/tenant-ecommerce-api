<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Imports\Jobs\ProcessImport;
use App\Modules\Imports\Models\DataImport;
use App\Modules\Imports\Services\DataImportService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    tenancy()->initialize($this->tenant);

    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
});

/**
 * Uploads a file and runs the queued import inline.
 */
function importFile(string $type, UploadedFile $file, ?string $mode = null): DataImport
{
    $id = test()->tenantJson('POST', '/api/admin/imports', array_filter(['import_type' => $type, 'file' => $file, 'mode' => $mode]), test()->auth)
        ->assertStatus(202)->json('data.id');
    tenancy()->initialize(test()->tenant);
    (new ProcessImport($id))->handle(app(DataImportService::class));

    return DataImport::query()->findOrFail($id);
}

function csvFile(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('imports products row by row through the product rules, reporting each rejected row', function (): void {
    Category::query()->create(['name' => 'Shoes', 'slug' => 'shoes', 'is_active' => true]);
    Product::query()->create(['name' => 'Runner', 'sku' => 'RUN-1', 'price' => '100', 'is_active' => true]);

    $csv = "SKU,Name,Price,Category slugs,Is active\n"
        ."RUN-1,,120,shoes,yes\n"            // update: price only
        ."CAP-1,Cap,30,shoes,no\n"           // create
        ."BAD-1,Bad,-5,,\n"                  // rejected by the product rules
        ."FX-1,=HYPERLINK(\"x\"),10,,\n"      // formulas refused
        ."NC-1,No cat,10,nowhere,\n"         // unknown category
        .",,,,\n";                           // empty row skipped

    $import = importFile('products', csvFile('catalogue.csv', $csv));
    expect($import->only(['status', 'processed_rows', 'created_count', 'updated_count', 'failed_count', 'last_row']))
        ->toBe(['status' => 'completed_with_errors', 'processed_rows' => 5, 'created_count' => 1, 'updated_count' => 1, 'failed_count' => 3, 'last_row' => 6]);

    tenancy()->initialize($this->tenant);
    expect((string) Product::query()->where('sku', 'RUN-1')->value('price'))->toBe('120.0000')
        ->and(Product::query()->where('sku', 'CAP-1')->first()?->is_active)->toBeFalse()
        ->and(Product::query()->whereIn('sku', ['BAD-1', 'FX-1', 'NC-1'])->exists())->toBeFalse();

    $errors = $this->tenantJson('GET', "/api/admin/imports/{$import->id}/errors", [], $this->auth)->assertOk()->json('data');
    expect(collect($errors)->pluck('row')->all())->toBe([4, 5, 6])
        ->and($errors[0]['field'])->toBe('price')->and($errors[1]['message'])->toContain('Formulas')->and($errors[2]['field'])->toBe('category_slugs');
    $this->tenantJson('GET', "/api/admin/imports/{$import->id}", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'completed_with_errors');
});

it('applies stock rows exactly once, even when the job runs again', function (): void {
    $shoe = Product::query()->create(['name' => 'Runner', 'sku' => 'RUN-1', 'price' => '100', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $shoe, null, '7', 'adjustment_in');
    $code = $this->main->code;

    $import = importFile('stock', csvFile('stock.csv', "sku,warehouse_code,quantity,note\nRUN-1,{$code},5,Delivery\nRUN-1,{$code},-2,Damaged\nRUN-1,NOPE,1,\n"));
    tenancy()->initialize($this->tenant);
    expect($import->status)->toBe('completed_with_errors')->and((string) Inventory::query()->where('product_id', $shoe->id)->value('quantity'))->toBe('10.000');

    // A retry (the job delivered again after a crash) resumes after last_row: nothing is applied twice.
    $import->forceFill(['status' => DataImport::PROCESSING])->save();
    app(DataImportService::class)->process($import->fresh());
    tenancy()->initialize($this->tenant);
    expect((string) Inventory::query()->where('product_id', $shoe->id)->value('quantity'))->toBe('10.000');

    // "set" moves on-hand to the given count.
    importFile('stock', csvFile('set.csv', "sku,warehouse_code,quantity\nRUN-1,{$code},4\n"), 'set');
    tenancy()->initialize($this->tenant);
    expect((string) Inventory::query()->where('product_id', $shoe->id)->value('quantity'))->toBe('4.000');
});

it('imports customers from XLSX, validates files and keeps imports private to their owner', function (): void {
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['email', 'name', 'phone', 'is_active'], ['ada@example.test', 'Ada', '08012345678', 'yes'], ['bad-email', 'Bad', null, null]]);
    $book->getActiveSheet()->setCellValue('B4', '=1+1');
    $book->getActiveSheet()->setCellValue('A4', 'fx@example.test');
    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    (new Xlsx($book))->save($path);

    $import = importFile('customers', new UploadedFile($path, 'customers.xlsx', null, null, true));
    tenancy()->initialize($this->tenant);
    expect($import->only(['created_count', 'failed_count']))->toBe(['created_count' => 1, 'failed_count' => 2])
        ->and(Customer::query()->where('email', 'ada@example.test')->value('phone'))->toBe('08012345678')
        ->and(Customer::query()->where('email', 'ada@example.test')->value('password'))->toBeNull();

    // A missing key column stops the whole file.
    expect(importFile('customers', csvFile('c.csv', "name\nAda\n"))->only(['status', 'error']))
        ->toMatchArray(['status' => 'failed'])->and(importFile('customers', csvFile('c.csv', "name\nAda\n"))->error)->toContain('email');

    // Wrong file types, unknown types and permissions.
    $this->tenantJson('POST', '/api/admin/imports', ['import_type' => 'products', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], $this->auth)->assertStatus(422);
    $this->tenantJson('POST', '/api/admin/imports', ['import_type' => 'orders', 'file' => csvFile('o.csv', "a\n1\n")], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'import_type_unknown');
    $this->tenantJson('POST', '/api/admin/imports', ['import_type' => 'stock', 'file' => csvFile('s.csv', "sku\nX\n"), 'mode' => 'upsert'], $this->auth)->assertStatus(422);

    tenancy()->initialize($this->tenant);
    $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $clerk->assignRole('staff');
    $clerkAuth = ['Authorization' => 'Bearer '.$clerk->createToken('t', ['staff'])->plainTextToken];
    $this->tenantJson('POST', '/api/admin/imports', ['import_type' => 'products', 'file' => csvFile('p.csv', "sku\nX\n")], $clerkAuth)->assertForbidden();
    $this->tenantJson('GET', "/api/admin/imports/{$import->id}", [], $clerkAuth)->assertForbidden();

    $types = collect($this->tenantJson('GET', '/api/admin/imports/types', [], $this->auth)->assertOk()->json('data'))->pluck('type')->all();
    expect($types)->toBe(['categories', 'products', 'customers', 'stock']);
    $this->tenantJson('GET', '/api/admin/imports/types/products/template', [], $this->auth)->assertOk()->assertDownload('products-template.csv');

    // Another store neither knows this token nor holds these imports.
    $this->subscribe($this->createTenant('b'), 'basic');
    $this->tenantJson('GET', "/api/admin/imports/{$import->id}", [], $this->auth, 'b')->assertUnauthorized();
});
