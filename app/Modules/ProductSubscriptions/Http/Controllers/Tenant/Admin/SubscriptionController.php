<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\ProductSubscriptions\Http\ProductSubscriptionPresenter;
use App\Modules\ProductSubscriptions\Models\CustomerSubscription;
use App\Modules\ProductSubscriptions\Services\ProductSubscriptionService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer subscriptions for staff (spec §55.3). Cancelling tells the
 * customer (§11.5).
 */
final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly ProductSubscriptionService $subscriptions,
        private readonly ProductSubscriptionPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(CustomerSubscription::STATUSES)],
            'customer_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->subscriptions->listSubscriptions($filters)
            ->through(fn (CustomerSubscription $s): array => $this->presenter->subscription($s, true)));
    }

    public function show(CustomerSubscription $subscription): JsonResponse
    {
        return APIResponse::success($this->presenter->subscription($this->subscriptions->getSubscription($subscription), true));
    }

    public function cancel(CustomerSubscription $subscription): JsonResponse
    {
        $cancelled = $this->subscriptions->cancelSubscription($subscription, true);

        return APIResponse::success($this->presenter->subscription($this->subscriptions->getSubscription($cancelled), true), 'Subscription cancelled');
    }
}
