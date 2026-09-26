<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An order's payment ledger and manual payments (spec §40.6, §40.7).
 */
final class OrderPaymentLedgerController extends Controller
{
    public function __construct(
        private readonly OrderPaymentLedgerService $ledger,
        private readonly OrderPresenter $presenter,
    ) {}

    public function index(Order $order): JsonResponse
    {
        return APIResponse::success($this->ledger->listPaymentsForOrder($order)->map(fn (OrderPayment $p): array => $this->presenter->payment($p))->values());
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        /** @var User $by */
        $by = $request->user();
        $payment = $this->ledger->recordPayment($order, $request->all(), $by, $request->attributes->get('idempotency_key'));

        return APIResponse::created($this->presenter->payment($payment), 'Payment recorded');
    }

    public function update(Request $request, OrderPayment $payment): JsonResponse
    {
        return APIResponse::success($this->presenter->payment($this->ledger->updatePayment($payment, $request->all())), 'Payment updated');
    }

    public function destroy(OrderPayment $payment): JsonResponse
    {
        $this->ledger->deletePayment($payment);

        return APIResponse::noContent('Payment deleted');
    }

    public function resolveRefund(Request $request, OrderPayment $payment): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in([OrderPayment::SUCCESSFUL, OrderPayment::FAILED])],
            'provider_reference' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        return APIResponse::success($this->presenter->payment($this->ledger->resolveRefund($payment, $validated['outcome'], $validated['provider_reference'] ?? null)), 'Refund resolved');
    }
}
