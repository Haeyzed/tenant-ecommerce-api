<?php

declare(strict_types=1);

namespace App\Modules\Wishlist\Services;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Wishlist\Models\WishlistItem;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The customer's wishlist (spec §42.2), keyed by product.
 */
final readonly class WishlistService
{
    public const int MAX_ITEMS = 500;

    public function __construct(private CartService $carts) {}

    /**
     * Idempotent.
     */
    public function addItem(Customer $customer, Product $product): WishlistItem
    {
        $existing = WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $product->is_active || $product->trashed()) {
            throw ApiException::unprocessable('product_unavailable', 'This product is not available.');
        }

        if (WishlistItem::query()->where('customer_id', $customer->id)->count() >= self::MAX_ITEMS) {
            throw ApiException::unprocessable('wishlist_full', 'A wishlist holds at most '.self::MAX_ITEMS.' products.');
        }

        try {
            $item = new WishlistItem;
            $item->forceFill(['customer_id' => $customer->id, 'product_id' => $product->id])->save();

            return $item;
        } catch (UniqueConstraintViolationException) {
            return WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->firstOrFail();
        }
    }

    public function removeItem(Customer $customer, Product $product): void
    {
        WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->delete();
    }

    /**
     * @return Collection<int, WishlistItem>
     */
    public function listItems(Customer $customer): Collection
    {
        return WishlistItem::query()->with(['product' => static fn ($q) => $q->with(['brand:id,name,slug', 'media', 'badges'])])
            ->where('customer_id', $customer->id)->orderByDesc('id')->get()
            ->filter(static fn (WishlistItem $i): bool => $i->product !== null)->values();
    }

    /**
     * Adds the product to the cart and removes it from the wishlist, in one
     * transaction; a variable product needs its variant.
     */
    public function moveToCart(Customer $customer, Product $product, Cart $cart, ?ProductVariant $variant = null, string $quantity = '1'): void
    {
        if (! WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->exists()) {
            throw ApiException::unprocessable('not_in_wishlist', 'This product is not in the wishlist.');
        }

        DB::connection('tenant')->transaction(function () use ($customer, $product, $cart, $variant, $quantity): void {
            $this->carts->addItem($cart, $product, $quantity, $variant);
            $this->removeItem($customer, $product);
        });
    }
}
