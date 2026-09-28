<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\CartResponder;
use App\Modules\GiftCards\Services\GiftCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cart's gift card (spec §38.7, §46.3). The amount applied is shown in
 * the quote (gift_card_amount_applied, amount_due).
 */
final class CartGiftCardController extends Controller
{
    public function __construct(
        private readonly GiftCardService $giftCards,
        private readonly CartResponder $responder,
    ) {}

    /**
     * Body: code. 422 gift_card_invalid (with reason) or gift_card_currency_mismatch.
     */
    public function store(Request $request): JsonResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        $cart = $this->responder->findOrFail($request);
        $this->giftCards->applyToCart($cart, $code);

        return $this->responder->respond($request, $cart, 'Gift card applied');
    }

    public function destroy(Request $request): JsonResponse
    {
        $cart = $this->responder->findOrFail($request);
        $this->giftCards->removeFromCart($cart);

        return $this->responder->respond($request, $cart, 'Gift card removed');
    }
}
