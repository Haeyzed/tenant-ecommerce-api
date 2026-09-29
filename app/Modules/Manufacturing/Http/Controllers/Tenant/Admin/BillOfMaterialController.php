<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Manufacturing\Http\ManufacturingPresenter;
use App\Modules\Manufacturing\Models\BillOfMaterial;
use App\Modules\Manufacturing\Services\BillOfMaterialService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recipes (spec §64.4).
 */
final class BillOfMaterialController extends Controller
{
    public function __construct(
        private readonly BillOfMaterialService $boms,
        private readonly ManufacturingPresenter $presenter,
    ) {}

    public function index(Product $product): JsonResponse
    {
        return APIResponse::success($this->boms->listBoms($product)->map(fn (BillOfMaterial $b): array => $this->presenter->bom($b))->all());
    }

    /**
     * Body: name, product_variant_id? (variable products), yield_quantity?, is_default?, notes?,
     * items[{component_product_id, component_product_variant_id?, quantity_required}]
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $bom = $this->boms->createBom($product, (array) $request->input('items', []), $request->only(['name', 'product_variant_id', 'yield_quantity', 'is_default', 'notes']));

        return APIResponse::created($this->presenter->bom($this->boms->getBom($bom), true), 'Recipe saved');
    }

    /**
     * Includes the calculated unit cost (§64.3).
     */
    public function show(BillOfMaterial $bom): JsonResponse
    {
        return APIResponse::success($this->presenter->bom($this->boms->getBom($bom), true));
    }

    /**
     * Body: name?, yield_quantity?, is_default?, notes?, items? (replaces the components).
     */
    public function update(Request $request, BillOfMaterial $bom): JsonResponse
    {
        $items = $request->has('items') ? (array) $request->input('items') : null;
        $this->boms->updateBom($bom, $items, $request->only(['name', 'yield_quantity', 'is_default', 'notes']));

        return APIResponse::success($this->presenter->bom($this->boms->getBom($bom->refresh()), true), 'Recipe updated');
    }

    public function destroy(BillOfMaterial $bom): JsonResponse
    {
        $this->boms->deleteBom($bom);

        return APIResponse::success(null, 'Recipe deleted');
    }

    public function setDefault(BillOfMaterial $bom): JsonResponse
    {
        return APIResponse::success($this->presenter->bom($this->boms->getBom($this->boms->setDefaultBom($bom)), true), 'Default recipe set');
    }
}
