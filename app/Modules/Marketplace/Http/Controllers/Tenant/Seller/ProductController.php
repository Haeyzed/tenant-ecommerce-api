<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Services\SellerProductService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A seller's own products (spec §50.3, §50.7). Another seller's or the
 * store's product is a 404.
 */
final class ProductController extends Controller
{
    public function __construct(
        private readonly SellerProductService $products,
        private readonly ProductService $catalog,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'moderation_status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'not_required'])],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->products->list($this->seller($request), $filters);
        $page->getCollection()->load(['brand:id,name', 'categories:id,name', 'media']);

        return APIResponse::success($page->through(fn (Product $p): array => $this->presenter->product($p, false)));
    }

    /**
     * Body: name, price, and the other simple-product fields (§27.3) a
     * seller may set. Pending review when the store requires it.
     */
    public function store(Request $request): JsonResponse
    {
        $product = $this->products->create($this->seller($request), $request->all());

        return APIResponse::created($this->presenter->product($this->catalog->getProduct($product), true), 'Product created');
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return APIResponse::success($this->presenter->product($this->catalog->getProduct($this->products->own($this->seller($request), $product)), true));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product = $this->products->update($this->seller($request), $product, $request->all());

        return APIResponse::success($this->presenter->product($product, true), 'Product updated');
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->products->delete($this->seller($request), $product);

        return APIResponse::success(null, 'Product deleted');
    }

    private function seller(Request $request): Seller
    {
        /** @var Seller */
        return $request->user();
    }
}
