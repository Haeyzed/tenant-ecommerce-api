<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Modules\Purchasing\Services\SupplierService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The products a supplier sells (spec §49.1): linking again updates the link.
 */
final class SupplierProductController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly PurchasingPresenter $presenter,
    ) {}

    /**
     * Body: product_id, supplier_sku?, cost_price?, lead_time_days?
     */
    public function store(Request $request, Supplier $supplier): JsonResponse
    {
        $productId = (int) $request->validate(['product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')]])['product_id'];
        $link = $this->suppliers->linkProduct($supplier, Product::query()->findOrFail($productId), $request->except('product_id'));

        return APIResponse::created($this->presenter->supplierProduct($link), 'Product linked');
    }

    public function destroy(Supplier $supplier, Product $product): JsonResponse
    {
        $this->suppliers->unlinkProduct($supplier, $product);

        return APIResponse::success(null, 'Product unlinked');
    }

    /**
     * The suppliers of one product, cheapest first.
     */
    public function forProduct(Product $product): JsonResponse
    {
        return APIResponse::success($this->suppliers->getSuppliersForProduct($product)->map(fn (SupplierProduct $l): array => $this->presenter->supplierProduct($l))->values());
    }
}
