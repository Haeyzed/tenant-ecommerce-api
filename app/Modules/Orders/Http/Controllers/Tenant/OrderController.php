<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Http\OrderAccess;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shopper's orders (spec §39.7): a customer's own, or a guest order by
 * its token.
 */
final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        if (! $customer instanceof Customer) {
            throw new ApiException('unauthenticated', 'Sign in to see your orders.', 401);
        }

        return APIResponse::success($this->orders->listOrdersForCustomer($customer)->through(fn (Order $o): array => $this->presenter->order($o, false, false)));
    }

    public function show(Request $request, Order $order, OrderPaymentService $payments): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);

        return APIResponse::success($this->presenter->order($this->orders->getOrder($order), true, false, $payments->balance($order)));
    }

    public function cancel(Request $request, Order $order, OrderPaymentService $payments): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);
        $this->orders->cancelOrder($order, 'Cancelled by the customer', true);

        return APIResponse::success($this->presenter->order($this->orders->getOrder($order->refresh()), true, false, $payments->balance($order)), 'Order cancelled');
    }
}
