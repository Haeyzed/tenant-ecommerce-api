<?php

declare(strict_types=1);

namespace App\Modules\Installments\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Installments\Http\InstallmentPresenter;
use App\Modules\Installments\Models\InstallmentPayment;
use App\Modules\Installments\Services\InstallmentPlanService;
use App\Modules\Orders\Http\OrderAccess;
use App\Modules\Orders\Models\Order;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A buyer's installment plan (spec §47.5), for the order's owner only.
 */
final class InstallmentController extends Controller
{
    public function __construct(private readonly InstallmentPlanService $plans) {}

    public function eligibility(Request $request, Order $order): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);
        $reason = $this->plans->ineligibility($order);

        return APIResponse::success([
            'eligible' => $reason === null,
            'reason' => $reason,
            'frequencies' => ['weekly', 'biweekly', 'monthly'],
            'max_installments' => InstallmentPlanService::MAX_INSTALLMENTS,
        ]);
    }

    /**
     * Body: number_of_installments (2–24), frequency (weekly, biweekly, monthly).
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);
        $plan = $this->plans->createPlan($order, (int) $request->input('number_of_installments'), (string) $request->input('frequency'));

        return APIResponse::created(InstallmentPresenter::plan($plan), 'Installment plan created');
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);
        $plan = $this->plans->getPlanForOrder($order) ?? throw new NotFoundHttpException;

        return APIResponse::success(InstallmentPresenter::plan($plan));
    }

    /**
     * Body: gateway. Returns {checkout_url, reference}; the authorization
     * is saved for the scheduled charges.
     */
    public function pay(Request $request, InstallmentPayment $payment): JsonResponse
    {
        $payment->loadMissing('plan.order');
        OrderAccess::assertCanView($request, $payment->plan->order);
        $gateway = (string) $request->validate(['gateway' => ['required', 'string']])['gateway'];

        return APIResponse::success($this->plans->payInstallment($payment, $gateway, $request->header('Idempotency-Key')), 'Payment started');
    }
}
