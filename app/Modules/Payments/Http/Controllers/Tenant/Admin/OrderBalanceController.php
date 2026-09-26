<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/orders/{order}/balance (spec §40.7,
 * orders.balance.view): total − net paid.
 */
final class OrderBalanceController extends Controller
{
    public function index(Order $order, OrderPaymentService $payments): JsonResponse
    {
        return APIResponse::success([
            'order_id' => $order->id,
            'currency_code' => $order->currency_code,
            'total' => (string) $order->total,
            'net_paid' => $payments->netPaid($order),
            'balance' => $payments->balance($order),
            'payment_status' => $order->payment_status,
        ]);
    }
}
