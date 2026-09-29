<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\ProductSubscriptions\Http\ProductSubscriptionPresenter;
use App\Modules\ProductSubscriptions\Models\ProductSubscriptionPlan;
use App\Modules\ProductSubscriptions\Services\ProductSubscriptionPlanService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A product's delivery schedules (spec §55.3). DELETE deactivates: existing
 * subscriptions keep renewing on the schedule.
 */
final class SubscriptionPlanController extends Controller
{
    public function __construct(
        private readonly ProductSubscriptionPlanService $plans,
        private readonly ProductSubscriptionPresenter $presenter,
    ) {}

    public function index(Product $product): JsonResponse
    {
        return APIResponse::success($this->plans->listPlansForProduct($product)->map(fn (ProductSubscriptionPlan $plan): array => $this->presenter->plan($plan))->all());
    }

    /**
     * Body: interval (weekly | biweekly | monthly | quarterly), interval_count? (1–12).
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        return APIResponse::created($this->presenter->plan($this->plans->createPlan($product, $request->only(['interval', 'interval_count']))), 'Subscription plan saved');
    }

    public function destroy(Product $product, ProductSubscriptionPlan $plan): JsonResponse
    {
        if ($plan->product_id !== $product->id) {
            throw new NotFoundHttpException;
        }

        return APIResponse::success($this->presenter->plan($this->plans->deactivatePlan($plan)), 'Subscription plan deactivated');
    }
}
