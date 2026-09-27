<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\CartResponder;
use App\Modules\Cart\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cart's currency (spec §38.7, §48.2): an active store currency with a
 * rate; 422 currency_not_supported otherwise. The response is the cart
 * re-priced in it.
 */
final class CartCurrencyController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartResponder $responder,
    ) {}

    public function update(Request $request): JsonResponse
    {
        $code = (string) $request->validate(['currency_code' => ['required', 'string', 'size:3']])['currency_code'];
        $cart = $this->responder->findOrFail($request);
        $this->carts->setCurrency($cart, $code);

        return $this->responder->respond($request, $cart, 'Currency changed');
    }
}
