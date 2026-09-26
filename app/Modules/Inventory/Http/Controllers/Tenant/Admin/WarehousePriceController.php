<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseProductPrice;
use App\Modules\Inventory\Services\WarehousePricingService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-warehouse prices of a product (spec §34). A price row is looked up
 * within its product.
 */
final class WarehousePriceController extends Controller
{
    public function __construct(
        private readonly WarehousePricingService $prices,
        private readonly InventoryPresenter $presenter,
    ) {}

    public function index(Product $product): JsonResponse
    {
        return APIResponse::success($this->prices->listWarehousePrices($product)->map(fn (WarehouseProductPrice $p): array => $this->presenter->price($p))->values());
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'product_variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.product_variants', 'id')->whereNull('deleted_at')],
            'price' => ['required'],
            'compare_at_price' => ['sometimes', 'nullable'],
        ]);

        $row = $this->prices->setWarehousePrice(
            $product,
            Warehouse::query()->findOrFail($validated['warehouse_id']),
            (string) $validated['price'],
            isset($validated['compare_at_price']) ? (string) $validated['compare_at_price'] : null,
            isset($validated['product_variant_id']) ? ProductVariant::query()->findOrFail($validated['product_variant_id']) : null,
        );

        return APIResponse::created($this->presenter->price($row), 'Warehouse price set');
    }

    public function update(Request $request, Product $product, int $price): JsonResponse
    {
        $row = $this->find($product, $price);
        $validated = $request->validate(['price' => ['required'], 'compare_at_price' => ['sometimes', 'nullable']]);

        return APIResponse::success($this->presenter->price($this->prices->updateWarehousePrice(
            $row, (string) $validated['price'], isset($validated['compare_at_price']) ? (string) $validated['compare_at_price'] : null,
        )), 'Warehouse price updated');
    }

    public function destroy(Product $product, int $price): JsonResponse
    {
        $this->prices->removeWarehousePrice($this->find($product, $price));

        return APIResponse::noContent('Warehouse price removed');
    }

    private function find(Product $product, int $id): WarehouseProductPrice
    {
        /** @var WarehouseProductPrice */
        return WarehouseProductPrice::query()->where('product_id', $product->id)->whereKey($id)->firstOrFail();
    }
}
