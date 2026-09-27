<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Suppliers and the products they sell (spec §49.1, §49.6).
 */
final readonly class SupplierService
{
    public const string ENTITY = 'supplier';

    public function __construct(private CustomFieldService $customFields) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSupplier(array $data): Supplier
    {
        [$validated, $custom] = $this->validate($data, null);

        return DB::connection('tenant')->transaction(function () use ($validated, $custom): Supplier {
            /** @var Supplier $supplier */
            $supplier = Supplier::query()->create($validated);
            $this->customFields->save($supplier, self::ENTITY, $custom);

            return $supplier;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSupplier(Supplier $supplier, array $data): Supplier
    {
        [$validated, $custom] = $this->validate($data, $supplier);

        DB::connection('tenant')->transaction(function () use ($supplier, $validated, $custom): void {
            $supplier->fill($validated);

            if (array_key_exists('is_active', $validated)) {
                $supplier->is_active = (bool) $validated['is_active'];
            }

            $supplier->save();
            $this->customFields->save($supplier, self::ENTITY, $custom);
        });

        return $supplier;
    }

    public function deactivateSupplier(Supplier $supplier): void
    {
        $supplier->forceFill(['is_active' => false])->save();
    }

    /**
     * Soft delete: purchase orders and payments keep naming the supplier.
     */
    public function deleteSupplier(Supplier $supplier): void
    {
        $supplier->delete();
    }

    /**
     * @param  array{search?: string, is_active?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Supplier>
     */
    public function listSuppliers(array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Supplier::query()
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($search !== '', static fn ($q) => $q->where(static fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('email', $search)
                ->orWhere('phone', $search)))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Links a product, or updates the link (§49.1).
     *
     * @param  array<string, mixed>  $data  supplier_sku, cost_price, lead_time_days
     */
    public function linkProduct(Supplier $supplier, Product $product, array $data): SupplierProduct
    {
        $validated = Validator::make($data, [
            'supplier_sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
        ])->validate();

        if (! in_array($product->product_type, Product::PHYSICAL, true) || $product->product_type === Product::BUNDLE) {
            throw ApiException::unprocessable('product_not_purchasable', 'Only stock-holding products (simple or variable) can be bought from a supplier.');
        }

        $link = SupplierProduct::query()->where('supplier_id', $supplier->id)->where('product_id', $product->id)->first()
            ?? (new SupplierProduct)->forceFill(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
        $link->fill($validated);

        if (isset($validated['cost_price'])) {
            $link->cost_price = Money::normalize((string) $validated['cost_price']);
        }

        $link->save();

        return $link->load('product:id,name,sku');
    }

    public function unlinkProduct(Supplier $supplier, Product $product): void
    {
        SupplierProduct::query()->where('supplier_id', $supplier->id)->where('product_id', $product->id)->delete();
    }

    /**
     * @return Collection<int, SupplierProduct>
     */
    public function getSuppliersForProduct(Product $product): Collection
    {
        return SupplierProduct::query()->with('supplier:id,name,is_active')->where('product_id', $product->id)->orderBy('cost_price')->get();
    }

    /**
     * @return Collection<int, SupplierProduct>
     */
    public function productsOf(Supplier $supplier): Collection
    {
        return SupplierProduct::query()->with('product:id,name,sku')->where('supplier_id', $supplier->id)->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validate(array $data, ?Supplier $existing): array
    {
        $req = $existing === null ? 'required' : 'sometimes';
        $validator = Validator::make($data, [
            'name' => [$req, 'string', 'max:160'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.countries', 'id')],
            'state_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.states', 'id')],
            'city_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.cities', 'id')],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => [$existing === null ? 'prohibited' : 'sometimes', 'boolean'],
            'custom_fields' => ['sometimes', 'array'],
        ]);

        $errors = $validator->errors()->toArray();
        $custom = [];

        try {
            $custom = $this->customFields->validate(self::ENTITY, (array) ($data['custom_fields'] ?? []), CustomFieldService::ADMIN, $existing === null);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();
        unset($validated['custom_fields']);

        return [$validated, $custom];
    }
}
