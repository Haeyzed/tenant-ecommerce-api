<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\OrderAccess;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online payment of an order (spec §40.2, §40.7).
 */
final class OrderPaymentController extends Controller
{
    public function __construct(private readonly OrderPaymentService $payments) {}

    /**
     * POST /api/orders/{order}/pay: returns the provider checkout URL.
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);
        $gateway = (string) $request->validate(['gateway' => ['required', 'string']])['gateway'];

        return APIResponse::success($this->payments->initiateOrderPayment($order, $gateway, $request->attributes->get('idempotency_key')), 'Continue to payment');
    }

    /**
     * POST /api/payments/verify after the redirect back. The reference is
     * a capability: it is only known to the payer.
     */
    public function verify(Request $request): JsonResponse
    {
        $reference = (string) $request->validate(['reference' => ['required', 'string', 'max:64']])['reference'];
        $payment = OrderPayment::query()->where('reference', $reference)->where('kind', OrderPayment::PAYMENT)->firstOrFail();
        OrderAccess::assertCanView($request, $payment->order);

        $payment = $this->payments->verifyOrderPayment($payment);
        $order = $payment->order()->firstOrFail();

        return APIResponse::success([
            'reference' => $payment->reference,
            'status' => $payment->status,
            'order' => ['id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status, 'payment_status' => $order->payment_status],
        ]);
    }

    /**
     * GET /api/payment-methods?currency=: providers available for online
     * payment in the store's current mode.
     */
    public function methods(Request $request, TenantSettingsService $settings): JsonResponse
    {
        $currency = strtoupper((string) ($request->validate(['currency' => ['sometimes', 'string', 'size:3']])['currency'] ?? $settings->get('default_currency', 'USD')));

        return APIResponse::success(['currency' => $currency, 'providers' => $this->payments->eligibleProviders($currency, (string) $settings->get('payment_mode', 'test'))]);
    }
}
