<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\CartResponder;
use App\Modules\Cart\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cart's coupon (spec §38.7): 422 coupon_not_applicable with a reason
 * when the coupon does not apply to this basket.
 */
final class CartCouponController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartResponder $responder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        $cart = $this->responder->findOrFail($request);
        $this->carts->applyCoupon($cart, $code, $this->responder->checkoutData($request));

        return $this->responder->respond($request, $cart, 'Coupon applied');
    }

    public function destroy(Request $request): JsonResponse
    {
        $cart = $this->responder->findOrFail($request);
        $this->carts->removeCoupon($cart);

        return $this->responder->respond($request, $cart, 'Coupon removed');
    }
}
