<?php

declare(strict_types=1);

namespace App\Modules\Installments\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Installments\Http\InstallmentPresenter;
use App\Modules\Installments\Models\InstallmentPayment;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Services\InstallmentPlanService;
use App\Modules\Orders\Http\OrderPresenter;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Installment plans in the back office (spec §47.5).
 */
final class InstallmentPlanController extends Controller
{
    public function __construct(
        private readonly InstallmentPlanService $plans,
        private readonly OrderPresenter $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(InstallmentPlan::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->plans->listPlans($filters)->through(static fn (InstallmentPlan $p): array => InstallmentPresenter::plan($p, true)));
    }

    public function show(InstallmentPlan $plan): JsonResponse
    {
        return APIResponse::success(InstallmentPresenter::plan($plan->load(['order', 'payments']), true));
    }

    public function cancel(InstallmentPlan $plan): JsonResponse
    {
        return APIResponse::success(InstallmentPresenter::plan($this->plans->cancelPlan($plan)->load(['order', 'payments']), true), 'Installment plan cancelled');
    }

    /**
     * Charges one installment now through the stored authorization.
     */
    public function charge(InstallmentPlan $plan, InstallmentPayment $payment): JsonResponse
    {
        if ($payment->installment_plan_id !== $plan->id) {
            throw new NotFoundHttpException;
        }

        $row = $this->plans->chargeInstallment($payment);

        return APIResponse::success([
            'payment' => $this->orders->payment($row),
            'plan' => InstallmentPresenter::plan($plan->refresh()->load(['order', 'payments']), true),
        ], 'Charge attempted');
    }
}
