<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Checkout\Support\Quote;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Promotions\Models\Coupon;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carts (spec §38.2, §38.3). A cart holds products and quantities only;
 * every read is re-priced by CheckoutService::quote(). Guest carts are
 * identified by a server-generated token.
 */
final readonly class CartService
{
    public const string MAX_QUANTITY = '9999';

    public function __construct(
        private InventoryService $inventory,
        private CheckoutService $checkout,
        private PricingService $pricing,
    ) {}

    /**
     * The cart of the customer or of the guest token, without creating one.
     */
    public function findCart(?Customer $customer, ?string $guestToken): ?Cart
    {
        if ($customer !== null) {
            return Cart::query()->where('customer_id', $customer->id)->first();
        }

        return $guestToken === null ? null : Cart::query()->where('guest_token', $guestToken)->whereNull('customer_id')->first();
    }

    /**
     * The single entry point for writes. An unknown or expired guest token
     * gets a fresh cart with a new token (a client never chooses its token).
     */
    public function getOrCreateCart(?Customer $customer, ?string $guestToken): Cart
    {
        $cart = $this->findCart($customer, $guestToken);

        if ($cart !== null) {
            return $cart;
        }

        $cart = new Cart;
        $cart->forceFill([
            'customer_id' => $customer?->id,
            'guest_token' => $customer === null ? (string) Str::uuid() : null,
            'currency_code' => $this->pricing->baseCurrency(),
            'last_activity_at' => now(),
        ]);

        try {
            $cart->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the customer's cart first.
            return Cart::query()->where('customer_id', $customer?->id)->firstOrFail();
        }

        return $cart;
    }

    public function addItem(Cart $cart, Product $product, string $quantity, ?ProductVariant $variant = null): CartItem
    {
        $product->loadMissing('unit');

        return DB::connection('tenant')->transaction(function () use ($cart, $product, $quantity, $variant): CartItem {
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

            /** @var CartItem|null $item */
            $item = $cart->items()->where('product_id', $product->id)->where('variant_key', $variant?->id ?? 0)->first();

            if ($item === null && $cart->items()->count() >= Cart::MAX_LINES) {
                throw ApiException::unprocessable('cart_full', 'A cart holds at most '.Cart::MAX_LINES.' different items.');
            }

            $total = Quantity::add($item === null ? Quantity::normalize(0) : (string) $item->quantity, $this->validQuantity($product, $quantity));
            $this->assertBuyable($product, $variant, $total);

            if ($item === null) {
                $item = new CartItem(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => $total]);
                $item->cart_id = $cart->id;
            } else {
                $item->quantity = $total;
            }

            $item->save();
            $this->touch($cart);

            return $item;
        });
    }

    /**
     * A quantity of 0 removes the line.
     */
    public function updateItemQuantity(CartItem $item, string $quantity): ?CartItem
    {
        if (is_numeric($quantity) && Quantity::cmp(Quantity::normalize($quantity), '0') === 0) {
            $this->removeItem($item);

            return null;
        }

        $item->loadMissing(['product.unit', 'variant']);
        $quantity = $this->validQuantity($item->product, $quantity);
        $this->assertBuyable($item->product, $item->variant, $quantity);

        $item->forceFill(['quantity' => $quantity])->save();
        $this->touch($item->cart_id);

        return $item;
    }

    public function removeItem(CartItem $item): void
    {
        $item->delete();
        $this->touch($item->cart_id);
    }

    public function clearCart(Cart $cart): void
    {
        DB::connection('tenant')->transaction(static function () use ($cart): void {
            $cart->items()->delete();
            $cart->forceFill(['coupon_id' => null, 'gift_card_id' => null, 'reward_points_to_redeem' => null, 'last_activity_at' => now()])->save();
        });
    }

    /**
     * Stored only when the engine applies it to this basket (§37.8);
     * otherwise 422 with the rejection reason.
     *
     * @param  array<string, mixed>  $checkoutData
     */
    public function applyCoupon(Cart $cart, string $code, array $checkoutData = []): Quote
    {
        $coupon = Coupon::query()->where('code', Coupon::normalize($code))->first();
        $previous = $cart->coupon_id;
        $cart->setRelation('coupon', $coupon);
        $quote = $this->checkout->quote($cart, $checkoutData);
        $reason = $coupon === null ? 'not_found' : ($cart->items->isEmpty() ? 'no_eligible_items' : $quote->promotions->couponRejectionReason);

        if ($reason !== null) {
            $cart->setRelation('coupon', $previous === null ? null : Coupon::query()->find($previous));

            throw ApiException::unprocessable('coupon_not_applicable', 'This coupon cannot be used on this cart.', ['reason' => $reason]);
        }

        /** @var Coupon $coupon */
        $cart->forceFill(['coupon_id' => $coupon->id, 'last_activity_at' => now()])->save();

        return $quote;
    }

    public function removeCoupon(Cart $cart): void
    {
        $cart->forceFill(['coupon_id' => null, 'last_activity_at' => now()])->save();
        $cart->setRelation('coupon', null);
    }

    /**
     * On login or registration with a guest token (§38.2): quantities of
     * overlapping items are summed; the customer's coupon wins; the guest
     * cart is deleted (or becomes the customer's cart).
     */
    public function mergeGuestCartIntoCustomer(string $guestToken, Customer $customer): ?Cart
    {
        return DB::connection('tenant')->transaction(function () use ($guestToken, $customer): ?Cart {
            $guest = Cart::query()->where('guest_token', $guestToken)->whereNull('customer_id')->lockForUpdate()->first();

            if ($guest === null) {
                return $this->findCart($customer, null);
            }

            $own = Cart::query()->where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($own === null) {
                $guest->forceFill(['customer_id' => $customer->id, 'guest_token' => null, 'last_activity_at' => now()])->save();

                return $guest;
            }

            foreach ($guest->items()->get() as $item) {
                $existing = $own->items()->where('product_id', $item->product_id)->where('variant_key', $item->product_variant_id ?? 0)->first();

                if ($existing !== null) {
                    $existing->forceFill(['quantity' => Quantity::min(Quantity::add((string) $existing->quantity, (string) $item->quantity), self::MAX_QUANTITY)])->save();
                } elseif ($own->items()->count() < Cart::MAX_LINES) {
                    $copy = new CartItem($item->only(['product_id', 'product_variant_id', 'quantity']));
                    $copy->cart_id = $own->id;
                    $copy->save();
                }
            }

            $own->forceFill(['coupon_id' => $own->coupon_id ?? $guest->coupon_id, 'last_activity_at' => now()])->save();
            $guest->delete();

            return $own;
        });
    }

    /**
     * Guest carts idle for 30 days are deleted (§38.2, §72.2).
     */
    public function purgeIdleGuestCarts(): int
    {
        $deleted = 0;

        do {
            $ids = Cart::query()->whereNull('customer_id')->where('last_activity_at', '<', now()->subDays(Cart::GUEST_TTL_DAYS))->limit(500)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : Cart::query()->whereKey($ids)->delete();
        } while ($ids->count() === 500);

        return $deleted;
    }

    /**
     * Positive, within the maximum, and whole unless the unit allows
     * decimals.
     */
    private function validQuantity(Product $product, string $quantity): string
    {
        $decimal = (bool) ($product->unit?->allows_decimal ?? false);

        validator(['quantity' => $quantity], [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_QUANTITY, $decimal ? 'decimal:0,3' : 'integer'],
        ], ['quantity.integer' => 'This product is sold in whole units.'])->validate();

        return Quantity::normalize($quantity);
    }

    /**
     * Visible product, matching variant, and enough available stock for
     * physical products (across active warehouses).
     */
    private function assertBuyable(Product $product, ?ProductVariant $variant, string $quantity): void
    {
        if (! CheckoutService::sellable($product, $variant)) {
            throw ValidationException::withMessages([$product->product_type === Product::VARIABLE ? 'product_variant_id' : 'product_id' => ['This item is not available.']]);
        }

        if (Quantity::cmp($quantity, self::MAX_QUANTITY) > 0) {
            throw ValidationException::withMessages(['quantity' => ['At most '.self::MAX_QUANTITY.' of an item per cart.']]);
        }

        $available = $this->inventory->getAvailableStock($product, $variant);

        if ($available !== null && Quantity::cmp($available, $quantity) < 0) {
            throw ApiException::unprocessable('insufficient_stock', 'Not enough stock is available.', ['available' => $available]);
        }
    }

    private function touch(Cart|int $cart): void
    {
        Cart::query()->whereKey($cart instanceof Cart ? $cart->id : $cart)->update(['last_activity_at' => now()]);
    }
}
