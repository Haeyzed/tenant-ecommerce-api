<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\ProductSubscriptions\Http\ProductSubscriptionPresenter;
use App\Modules\ProductSubscriptions\Models\ProductSubscriptionPlan;
use App\Modules\ProductSubscriptions\Services\ProductSubscriptionPlanService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/products/{product}/subscription-plans (spec §55.3): the active
 * schedules of a visible product, and its subscription discount. Empty
 * when the product is not subscribable.
 */
final class SubscriptionPlanController extends Controller
{
    public function __construct(
        private readonly ProductSubscriptionPlanService $plans,
        private readonly ProductSubscriptionPresenter $presenter,
    ) {}

    public function index(string $product): JsonResponse
    {
        $model = (ctype_digit($product) ? Product::query()->visible()->whereKey((int) $product)->first() : null)
            ?? Product::query()->visible()->where('slug', $product)->first()
            ?? throw new NotFoundHttpException('Not found.');

        return APIResponse::success([
            'product_id' => $model->id,
            'is_subscribable' => $model->is_subscribable,
            'discount_percent' => $model->subscription_discount_percent === null ? null : (string) $model->subscription_discount_percent,
            'plans' => $model->is_subscribable
                ? $this->plans->listPlansForProduct($model, true)->map(fn (ProductSubscriptionPlan $plan): array => $this->presenter->plan($plan))->all()
                : [],
        ]);
    }
}
