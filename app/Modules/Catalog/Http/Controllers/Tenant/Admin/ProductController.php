<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product administration (spec §27.5).
 */
final class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly CatalogPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'product_type' => ['sometimes', Rule::in(Product::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
            'brand_id' => ['sometimes', 'integer'],
            'category_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->products->listProducts($filters)->through(fn (Product $p): array => $this->presenter->adminProduct($p, false)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->adminProduct($this->products->createProduct($request->all()), true), 'Product created');
    }

    public function show(Product $product): JsonResponse
    {
        return APIResponse::success($this->presenter->adminProduct($this->products->getProduct($product), true));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        return APIResponse::success($this->presenter->adminProduct($this->products->updateProduct($product, $request->all()), true), 'Product updated');
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->products->deleteProduct($product);

        return APIResponse::noContent('Product deleted');
    }

    public function duplicate(Product $product): JsonResponse
    {
        return APIResponse::created($this->presenter->adminProduct($this->products->duplicateProduct($product), true), 'Product duplicated');
    }

    /**
     * §70.12: at most 100 ids; one result per item.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        return APIResponse::success(['results' => $this->products->bulk($validated['action'], $validated['ids'])]);
    }
}
