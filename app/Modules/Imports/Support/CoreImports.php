<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\CategoryService;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Services\CustomerGroupService;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Imports\Models\DataImport;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Shared\Support\Quantity;
use DomainException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The core-commerce import types (D-135): master data only. Each row goes
 * through the owning service (the same validation and rules as the API);
 * the key column decides create or update, within the chosen mode.
 * Transactional records (orders, payments, ledgers) are never imported.
 */
final class CoreImports
{
    public static function register(ImportRegistry $registry): void
    {
        $registry->register(new ImportDefinition(
            type: 'categories',
            label: 'Categories',
            columns: ['slug' => 'Unique key (lower-case-with-dashes)', 'name' => 'Name (required for new)', 'parent_slug' => 'Slug of the parent (earlier in the file or existing)',
                'description' => 'Description', 'is_active' => 'yes / no', 'sort_order' => 'Whole number'],
            required: ['slug'],
            apply: static function (array $row, DataImport $import): string {
                $slug = Str::slug((string) $row['slug']);
                $existing = Category::query()->where('slug', $slug)->first();
                self::assertMode($import, $existing !== null);
                $data = array_filter([
                    'name' => $row['name'] ?? null,
                    'description' => $row['description'] ?? null,
                    'is_active' => self::bool($row, 'is_active'),
                    'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : null,
                    'parent_id' => isset($row['parent_slug']) ? (Category::query()->where('slug', Str::slug((string) $row['parent_slug']))->value('id')
                        ?? throw ValidationException::withMessages(['parent_slug' => ['No category has this slug (list parents before their children).']])) : null,
                ], static fn ($v): bool => $v !== null);

                $service = app(CategoryService::class);

                if ($existing !== null) {
                    $service->updateCategory($existing, $data);

                    return 'updated';
                }

                $service->createCategory([...$data, 'slug' => $slug]);

                return 'created';
            },
            permission: 'categories.create',
        ));

        $registry->register(new ImportDefinition(
            type: 'products',
            label: 'Products',
            columns: ['sku' => 'Unique key', 'name' => 'Name (required for new)', 'product_type' => 'simple (default), digital or service; new products only',
                'price' => 'Price (required for new)', 'compare_at_price' => 'Compare-at price', 'cost_price' => 'Cost price', 'description' => 'Description',
                'category_slugs' => 'Category slugs, comma-separated', 'barcode' => 'Barcode', 'tax_class' => 'standard, reduced or exempt', 'is_active' => 'yes / no'],
            required: ['sku'],
            apply: static function (array $row, DataImport $import): string {
                $sku = (string) $row['sku'];
                $existing = Product::query()->where('sku', $sku)->first();
                self::assertMode($import, $existing !== null);

                if ($existing !== null && $existing->product_type === Product::VARIABLE) {
                    throw new DomainException('A variable product is not updated by import; its variants carry the SKUs.');
                }

                $data = array_filter([
                    'name' => $row['name'] ?? null,
                    'description' => $row['description'] ?? null,
                    'price' => $row['price'] ?? null,
                    'compare_at_price' => $row['compare_at_price'] ?? null,
                    'cost_price' => $row['cost_price'] ?? null,
                    'barcode' => isset($row['barcode']) ? (string) $row['barcode'] : null,
                    'tax_class' => $row['tax_class'] ?? null,
                    'is_active' => self::bool($row, 'is_active'),
                    'category_ids' => isset($row['category_slugs']) ? self::categoryIds((string) $row['category_slugs']) : null,
                ], static fn ($v): bool => $v !== null);

                $service = app(ProductService::class);

                if ($existing !== null) {
                    $service->updateProduct($existing, $data);

                    return 'updated';
                }

                $type = (string) ($row['product_type'] ?? Product::SIMPLE);

                if (! in_array($type, [Product::SIMPLE, Product::DIGITAL, Product::SERVICE], true)) {
                    throw ValidationException::withMessages(['product_type' => ['Import creates simple, digital or service products.']]);
                }

                $service->createProduct([...$data, 'sku' => $sku, 'product_type' => $type]);

                return 'created';
            },
            permission: 'products.create',
        ));

        $registry->register(new ImportDefinition(
            type: 'customers',
            label: 'Customers',
            columns: ['email' => 'Unique key', 'name' => 'Name (required for new)', 'phone' => 'Phone', 'customer_group' => 'Group name', 'is_active' => 'yes / no'],
            required: ['email'],
            apply: static function (array $row, DataImport $import): string {
                $email = strtolower((string) $row['email']);
                $existing = Customer::withTrashed()->where('email', $email)->first();
                self::assertMode($import, $existing !== null);
                $by = $import->requestedBy;
                $service = app(CustomerService::class);
                $group = isset($row['customer_group']) ? (CustomerGroup::query()->where('name', (string) $row['customer_group'])->first()
                    ?? throw ValidationException::withMessages(['customer_group' => ['No customer group has this name.']])) : null;
                $active = self::bool($row, 'is_active');

                if ($existing !== null && ($existing->trashed() || $existing->anonymized_at !== null)) {
                    throw new DomainException('This customer was erased and cannot be updated.');
                }

                // Imported customers have no password; they set one through "forgot password". Nothing is emailed.
                $customer = $existing !== null
                    ? $service->updateCustomer($existing, array_filter(['name' => $row['name'] ?? null, 'phone' => isset($row['phone']) ? (string) $row['phone'] : null], static fn ($v): bool => $v !== null), $by)
                    : $service->createCustomer(['name' => $row['name'] ?? null, 'email' => $email, 'phone' => isset($row['phone']) ? (string) $row['phone'] : null], $by);

                if ($group !== null) {
                    app(CustomerGroupService::class)->assignCustomerToGroup($customer, $group);
                }

                if ($active === true && ! $customer->is_active) {
                    $service->activateCustomer($customer, $by);
                } elseif ($active === false && $customer->is_active) {
                    $service->deactivateCustomer($customer, $by);
                }

                return $existing !== null ? 'updated' : 'created';
            },
            permission: 'customers.create',
            sensitive: ['phone'],
        ));

        $registry->register(new ImportDefinition(
            type: 'stock',
            label: 'Stock levels',
            columns: ['sku' => 'Product or variant SKU', 'warehouse_code' => 'Warehouse code', 'quantity' => 'adjust: the change (+ or −); set: the new on-hand count',
                'unit_cost' => 'Unit cost of stock added (optional)', 'note' => 'Note on the movement'],
            required: ['sku', 'warehouse_code', 'quantity'],
            apply: static function (array $row, DataImport $import): string {
                if (! is_numeric($row['quantity'])) {
                    throw ValidationException::withMessages(['quantity' => ['Enter a number.']]);
                }

                $sku = (string) $row['sku'];
                $variant = ProductVariant::query()->where('sku', $sku)->first();
                $product = $variant?->product ?? Product::query()->where('sku', $sku)->first()
                    ?? throw ValidationException::withMessages(['sku' => ['No product or variant has this SKU.']]);
                $warehouse = Warehouse::query()->where('code', (string) $row['warehouse_code'])->where('is_active', true)->first()
                    ?? throw ValidationException::withMessages(['warehouse_code' => ['No active warehouse has this code.']]);
                $quantity = Quantity::normalize((string) $row['quantity']);

                // "set": the change from what is on hand now, read under the row lock.
                if ($import->mode === 'set') {
                    $current = (string) (Inventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
                        ->where('product_variant_id', $variant?->id)->lockForUpdate()->value('quantity') ?? '0');
                    $quantity = Quantity::add($quantity, Quantity::neg(Quantity::normalize($current)));
                }

                if (Quantity::cmp($quantity, '0') === 0) {
                    return 'skipped';
                }

                $in = Quantity::cmp($quantity, '0') > 0;
                $note = mb_substr('Import #'.$import->id.' row '.$row['_row'].(isset($row['note']) ? ': '.$row['note'] : ''), 0, 255);
                app(InventoryService::class)->adjustStock($warehouse, $product, $variant, $quantity, $in ? 'adjustment_in' : 'adjustment_out', $import, $note,
                    $in && isset($row['unit_cost']) && is_numeric($row['unit_cost']) ? (string) $row['unit_cost'] : null);

                return 'updated';
            },
            permission: 'stock-adjustments.create',
            modes: ['adjust', 'set'],
        ));
    }

    /**
     * create refuses existing keys; update refuses unknown keys.
     */
    private static function assertMode(DataImport $import, bool $exists): void
    {
        if ($import->mode === 'create' && $exists) {
            throw new DomainException('This key already exists (the import only creates).');
        }

        if ($import->mode === 'update' && ! $exists) {
            throw new DomainException('Nothing has this key (the import only updates).');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function bool(array $row, string $key): ?bool
    {
        if (! isset($row[$key])) {
            return null;
        }

        return match (strtolower(trim((string) $row[$key]))) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => throw ValidationException::withMessages([$key => ['Use yes or no.']]),
        };
    }

    /**
     * @return list<int>
     */
    private static function categoryIds(string $slugs): array
    {
        $wanted = array_values(array_filter(array_map(static fn (string $s): string => Str::slug(trim($s)), explode(',', $slugs))));
        $ids = Category::query()->whereIn('slug', $wanted)->pluck('id', 'slug');
        $missing = array_diff($wanted, $ids->keys()->all());

        if ($missing !== []) {
            throw ValidationException::withMessages(['category_slugs' => ['Unknown categories: '.implode(', ', $missing).'.']]);
        }

        return $ids->values()->map(static fn ($id): int => (int) $id)->all();
    }
}
