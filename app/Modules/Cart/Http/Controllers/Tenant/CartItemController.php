<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\CartResponder;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cart lines (spec §38.7). Adding the first item issues a guest token when
 * the shopper has none. A line is looked up within the requester's cart.
 */
final class CartItemController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartResponder $responder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')],
            'product_variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.product_variants', 'id')->whereNull('deleted_at')],
            'quantity' => ['sometimes'],
        ]);

        $cart = $this->responder->getOrCreate($request);
        $this->carts->addItem(
            $cart,
            Product::query()->findOrFail($validated['product_id']),
            (string) ($validated['quantity'] ?? '1'),
            isset($validated['product_variant_id']) ? ProductVariant::query()->findOrFail($validated['product_variant_id']) : null,
        );

        return $this->responder->respond($request, $cart, 'Added to cart', 201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $quantity = $request->validate(['quantity' => ['required']])['quantity'];
        $cart = $this->responder->findOrFail($request);
        $this->carts->updateItemQuantity($this->find($cart, $item), (string) $quantity);

        return $this->responder->respond($request, $cart, 'Cart updated');
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $cart = $this->responder->findOrFail($request);
        $this->carts->removeItem($this->find($cart, $item));

        return $this->responder->respond($request, $cart, 'Removed from cart');
    }

    private function find(Cart $cart, int $id): CartItem
    {
        /** @var CartItem */
        return CartItem::query()->where('cart_id', $cart->id)->whereKey($id)->firstOrFail();
    }
}
