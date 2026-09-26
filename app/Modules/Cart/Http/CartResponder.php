<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Services\CartService;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Customers\Models\Customer;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resolves the requester's cart (customer, or guest token) and renders a
 * cart with its quote. A guest cart's token is returned in the body and in
 * the X-Guest-Token header (§38.2).
 */
final readonly class CartResponder
{
    public function __construct(
        private CartService $carts,
        private CheckoutService $checkout,
    ) {}

    public function customer(Request $request): ?Customer
    {
        $user = $request->user();

        return $user instanceof Customer ? $user : null;
    }

    public function find(Request $request): ?Cart
    {
        return $this->carts->findCart($this->customer($request), ResolveGuestToken::from($request));
    }

    public function findOrFail(Request $request): Cart
    {
        return $this->find($request) ?? throw new ApiException('cart_not_found', 'There is no cart yet.', 404);
    }

    public function getOrCreate(Request $request): Cart
    {
        return $this->carts->getOrCreateCart($this->customer($request), ResolveGuestToken::from($request));
    }

    /**
     * The quote inputs a cart read accepts: a saved or inline address and a
     * shipping method.
     *
     * @return array<string, mixed>
     */
    public function checkoutData(Request $request): array
    {
        return $request->validate([
            'address_id' => ['sometimes', 'integer'],
            'country_id' => ['sometimes', 'integer'],
            'state_id' => ['sometimes', 'nullable', 'integer'],
            'shipping_method_id' => ['sometimes', 'integer'],
            'guest_email' => ['sometimes', 'email:rfc', 'max:255'],
        ]);
    }

    public function respond(Request $request, ?Cart $cart, string $message = 'OK', int $status = 200): JsonResponse
    {
        if ($cart === null) {
            return APIResponse::success(['id' => null, 'guest_token' => null, 'items_count' => 0, 'quote' => null], $message);
        }

        $cart->unsetRelation('items');
        $quote = $this->checkout->quote($cart, $this->checkoutData($request));

        $response = APIResponse::success([
            'id' => $cart->id,
            'guest_token' => $cart->guest_token,
            'items_count' => count($quote->lines),
            'quote' => $quote->toArray(),
        ], $message, [], $status);

        if ($cart->guest_token !== null) {
            $response->headers->set('X-Guest-Token', $cart->guest_token);
        }

        return $response;
    }
}
