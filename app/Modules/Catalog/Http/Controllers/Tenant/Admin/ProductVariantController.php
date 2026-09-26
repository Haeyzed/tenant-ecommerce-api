<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Variants of a variable product (spec §28.6). A variant is looked up
 * within its product, so another product's variant is never found.
 */
final class ProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly CatalogPresenter $presenter,
    ) {}

    public function index(Product $product): JsonResponse
    {
        return APIResponse::success($product->variants()->with('optionValues.option')->get()->map(fn (ProductVariant $v): array => $this->presenter->adminVariant($v))->values());
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $variant = $this->products->createVariant($product, (array) $request->input('option_value_ids', []), $request->all());

        return APIResponse::created($this->presenter->adminVariant($variant), 'Variant created');
    }

    public function update(Request $request, Product $product, int $variant): JsonResponse
    {
        return APIResponse::success($this->presenter->adminVariant($this->products->updateVariant($this->find($product, $variant), $request->all())), 'Variant updated');
    }

    public function destroy(Product $product, int $variant): JsonResponse
    {
        $this->products->deleteVariant($this->find($product, $variant));

        return APIResponse::noContent('Variant deleted');
    }

    private function find(Product $product, int $id): ProductVariant
    {
        /** @var ProductVariant */
        return ProductVariant::query()->where('product_id', $product->id)->whereKey($id)->firstOrFail();
    }
}
