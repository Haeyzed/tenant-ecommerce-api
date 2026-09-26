<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\CartResponder;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/orders (spec §38.6, §38.7): places the cart as an order. An
 * unpaid order continues with POST /api/orders/{order}/pay.
 */
final class CheckoutController extends Controller
{
    public function store(Request $request, CartResponder $carts, CheckoutService $checkout, OrderService $orders, OrderPaymentService $payments, OrderPresenter $presenter): JsonResponse
    {
        $order = $checkout->placeOrder(
            $carts->findOrFail($request),
            $request->all(),
            $request->attributes->get('idempotency_key'),
        );

        return APIResponse::created([
            ...$presenter->order($orders->getOrder($order), true, false, $payments->balance($order)),
            'payment_methods' => $order->payment_status === 'paid' ? [] : $payments->eligibleProviders($order->currency_code, OrderPaymentService::modeOf($order)),
        ], 'Order placed');
    }
}
