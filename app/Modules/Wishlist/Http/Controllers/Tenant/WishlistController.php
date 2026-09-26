<?php

declare(strict_types=1);

namespace App\Modules\Wishlist\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Wishlist\Models\WishlistItem;
use App\Modules\Wishlist\Services\WishlistService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-in customer's wishlist (spec §42.3), keyed by product.
 */
final class WishlistController extends Controller
{
    public function __construct(private readonly WishlistService $wishlist) {}

    public function index(Request $request, CatalogPresenter $catalog): JsonResponse
    {
        $items = $this->wishlist->listItems($this->customer($request));
        $cards = collect($catalog->storefrontCards($items->map(static fn (WishlistItem $i): Product => $i->product)))->keyBy('id');

        return APIResponse::success($items->map(static fn (WishlistItem $i): array => [
            'added_at' => $i->created_at->toIso8601String(),
            'product' => $cards[$i->product_id] ?? null,
        ])->values());
    }

    public function store(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')]])['product_id'];
        $this->wishlist->addItem($this->customer($request), Product::query()->findOrFail($id));

        return APIResponse::created(['product_id' => $id], 'Saved to wishlist');
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        $this->wishlist->removeItem($this->customer($request), Product::withTrashed()->findOrFail($product));

        return APIResponse::noContent('Removed from wishlist');
    }

    public function moveToCart(Request $request, int $product, CartService $carts): JsonResponse
    {
        $validated = $request->validate([
            'product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
        ]);
        $customer = $this->customer($request);
        $variant = isset($validated['product_variant_id']) ? ProductVariant::query()->where('product_id', $product)->findOrFail($validated['product_variant_id']) : null;

        $this->wishlist->moveToCart($customer, Product::query()->findOrFail($product), $carts->getOrCreateCart($customer, null), $variant, (string) ($validated['quantity'] ?? '1'));

        return APIResponse::success(null, 'Moved to cart');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
