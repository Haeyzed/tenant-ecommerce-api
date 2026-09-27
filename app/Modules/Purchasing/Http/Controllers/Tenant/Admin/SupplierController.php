<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suppliers (spec §49.7).
 */
final class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly PurchasingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->suppliers->listSuppliers($filters)->through(fn (Supplier $s): array => $this->presenter->supplier($s)));
    }

    /**
     * Body: name, contact_name?, email?, phone?, address_line?, country_id?, state_id?, city_id?, payment_terms?, custom_fields?
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->supplier($this->suppliers->createSupplier($request->all()), true), 'Supplier created');
    }

    /**
     * With the products the supplier sells.
     */
    public function show(Supplier $supplier): JsonResponse
    {
        return APIResponse::success([
            ...$this->presenter->supplier($supplier, true),
            'products' => $this->suppliers->productsOf($supplier)->map(fn ($link): array => $this->presenter->supplierProduct($link))->values(),
        ]);
    }

    /**
     * Body: any store field, is_active?.
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        return APIResponse::success($this->presenter->supplier($this->suppliers->updateSupplier($supplier, $request->all()), true), 'Supplier updated');
    }

    /**
     * Soft delete: orders and payments keep naming the supplier.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->suppliers->deleteSupplier($supplier);

        return APIResponse::success(null, 'Supplier deleted');
    }
}
