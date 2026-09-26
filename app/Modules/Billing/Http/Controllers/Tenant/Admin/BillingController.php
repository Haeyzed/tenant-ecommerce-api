<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\PaymentTransactionResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Services\PlatformCouponService;
use App\Modules\Billing\Services\PlatformPaymentGatewayService;
use App\Modules\Billing\Services\SubscriptionBillingService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Services\PlanService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The tenant owner's billing (spec §14.6). Runs in the tenant context and
 * calls landlord services under §6.4; the tenant is always the current
 * one, never a request parameter.
 */
final class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionBillingService $billing,
        private readonly PlanService $plans,
        private readonly PlatformCouponService $coupons,
        private readonly PlatformPaymentGatewayService $gateways,
    ) {}

    /**
     * Active public plans priced in the tenant's billing currency, with the
     * providers able to charge each price.
     */
    public function plans(): JsonResponse
    {
        $tenant = $this->tenant();
        $currency = $this->subscriptions->getCurrentSubscription($tenant)?->currency_code ?? $tenant->default_currency;
        $plans = $this->plans->listPublicPlans($currency);

        $plans = $plans->map(function (array $plan) use ($tenant): array {
            foreach ($plan['prices'] as $i => $price) {
                $plan['prices'][$i]['gateways'] = $this->gateways->availableFor($tenant, $price['currency_code'])->pluck('provider')->values()->all();
            }

            return $plan;
        });

        return APIResponse::success($plans->values());
    }

    public function current(): JsonResponse
    {
        $tenant = $this->tenant();
        $subscription = $this->subscriptions->getCurrentSubscription($tenant);

        return APIResponse::success($subscription === null ? null : [
            'subscription' => new SubscriptionResource($subscription->load('plan')),
            'next_cycle' => $this->billing->calculateCycleTotal($subscription),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'billing_interval' => ['required', Rule::in(PlanService::INTERVALS)],
            'gateway' => ['required', 'string', Rule::in(['flutterwave', 'paystack', 'stripe'])],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $result = $this->subscriptions->subscribeTenantToPlan(
            $this->tenant(),
            $this->publicPlan((int) $validated['plan_id']),
            $validated['billing_interval'],
            $validated['gateway'],
            $validated['coupon_code'] ?? null,
        );

        return APIResponse::created([
            'checkout_url' => $result['checkout_url'],
            'reference' => $result['reference'],
            'status' => $result['status'],
            'subscription' => new SubscriptionResource($result['subscription']->load('plan')),
        ], 'Checkout started');
    }

    public function couponPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'coupon_code' => ['required', 'string', 'max:32'],
            'plan_id' => ['required', 'integer'],
            'billing_interval' => ['required', Rule::in(PlanService::INTERVALS)],
        ]);

        $tenant = $this->tenant();
        $price = $this->plans->getPriceForTenant($this->publicPlan((int) $validated['plan_id']), $tenant, $validated['billing_interval']);
        $result = $this->coupons->validate($validated['coupon_code'], $price, $tenant, $tenant->email);

        return APIResponse::success([
            'valid' => $result['valid'],
            'reason' => $result['reason'],
            'discount' => $result['discount'],
            'currency_code' => $result['currency_code'],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'billing_interval' => ['sometimes', Rule::in(PlanService::INTERVALS)],
        ]);

        return APIResponse::success($this->subscriptions->previewPlanChange(
            $this->tenant(),
            $this->publicPlan((int) $validated['plan_id']),
            $validated['billing_interval'] ?? null,
        ));
    }

    public function swapPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
            'billing_interval' => ['sometimes', Rule::in(PlanService::INTERVALS)],
            'confirm_impact' => ['sometimes', 'boolean'],
        ]);

        $subscription = $this->subscriptions->swapTenantPlan(
            $this->tenant(),
            $this->publicPlan((int) $validated['plan_id']),
            $validated['billing_interval'] ?? null,
            $request->boolean('confirm_impact'),
        );

        return APIResponse::success(new SubscriptionResource($subscription->load('plan')), 'Plan change accepted');
    }

    public function cancel(): JsonResponse
    {
        $subscription = $this->subscriptions->cancelTenantSubscription($this->tenant());

        return APIResponse::success(new SubscriptionResource($subscription->load('plan')), 'Subscription cancelled');
    }

    public function transactions(Request $request): JsonResponse
    {
        $page = PaymentTransaction::query()
            ->where('tenant_id', $this->tenant()->getTenantKey())
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return APIResponse::success(PaymentTransactionResource::collection($page));
    }

    /**
     * Tenants may choose only active public plans; private plans are
     * assigned by a platform user (§11.6).
     */
    private function publicPlan(int $id): Plan
    {
        return Plan::query()->where('is_active', true)->where('is_public', true)->findOrFail($id);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }
}
