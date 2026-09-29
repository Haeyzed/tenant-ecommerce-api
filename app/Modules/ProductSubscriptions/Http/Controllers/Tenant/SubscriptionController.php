<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\ProductSubscriptions\Http\ProductSubscriptionPresenter;
use App\Modules\ProductSubscriptions\Models\CustomerSubscription;
use App\Modules\ProductSubscriptions\Models\ProductSubscriptionPlan;
use App\Modules\ProductSubscriptions\Services\ProductSubscriptionService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer's own product subscriptions (spec §55.3). Another customer's
 * subscription is a 404.
 */
final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly ProductSubscriptionService $subscriptions,
        private readonly ProductSubscriptionPresenter $presenter,
        private readonly OrderPresenter $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return APIResponse::success($this->subscriptions->listCustomerSubscriptions($this->customer($request))
            ->map(fn (CustomerSubscription $s): array => $this->presenter->subscription($s, false))->all());
    }

    /**
     * Body: product_id, product_subscription_plan_id, product_variant_id?,
     * quantity?, address_id, shipping_method_id? (physical products),
     * currency_code?. Returns the subscription and its first order: pay the
     * order through POST /api/orders/{order}/pay to start the subscription.
     */
    public function store(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_subscription_plan_id' => ['required', 'integer'],
        ]);
        $product = Product::query()->visible()->find((int) $ids['product_id'])
            ?? throw ApiException::unprocessable('item_unavailable', 'This product is not available.');
        $plan = ProductSubscriptionPlan::query()->where('product_id', $product->id)->find((int) $ids['product_subscription_plan_id'])
            ?? throw ApiException::unprocessable('subscription_plan_unavailable', 'This delivery schedule is not offered for the product.');

        $created = $this->subscriptions->createSubscription($this->customer($request), $product, $plan,
            $request->only(['product_variant_id', 'quantity', 'address_id', 'shipping_method_id', 'currency_code']));

        return APIResponse::created([
            'subscription' => $this->presenter->subscription($this->subscriptions->getSubscription($created['subscription']), false),
            'order' => $this->orders->order($created['order']->load('items'), true, false),
        ], 'Subscription created: pay the order to start it');
    }

    public function show(Request $request, CustomerSubscription $subscription): JsonResponse
    {
        return APIResponse::success($this->presenter->subscription($this->subscriptions->getSubscription($this->own($request, $subscription)), false));
    }

    /**
     * Body: quantity?, product_variant_id?, address_id?, shipping_method_id?
     * (from the next renewal on).
     */
    public function update(Request $request, CustomerSubscription $subscription): JsonResponse
    {
        $updated = $this->subscriptions->updateQuantityOrVariant($this->own($request, $subscription),
            $request->only(['quantity', 'product_variant_id', 'address_id', 'shipping_method_id']));

        return APIResponse::success($this->presenter->subscription($this->subscriptions->getSubscription($updated), false), 'Subscription updated');
    }

    public function pause(Request $request, CustomerSubscription $subscription): JsonResponse
    {
        $paused = $this->subscriptions->pauseSubscription($this->own($request, $subscription));

        return APIResponse::success($this->presenter->subscription($this->subscriptions->getSubscription($paused), false), 'Subscription paused');
    }

    public function resume(Request $request, CustomerSubscription $subscription): JsonResponse
    {
        $resumed = $this->subscriptions->resumeSubscription($this->own($request, $subscription));

        return APIResponse::success($this->presenter->subscription($this->subscriptions->getSubscription($resumed), false), 'Subscription resumed');
    }

    public function destroy(Request $request, CustomerSubscription $subscription): JsonResponse
    {
        $cancelled = $this->subscriptions->cancelSubscription($this->own($request, $subscription));

        return APIResponse::success($this->presenter->subscription($this->subscriptions->getSubscription($cancelled), false), 'Subscription cancelled');
    }

    private function own(Request $request, CustomerSubscription $subscription): CustomerSubscription
    {
        return $subscription->customer_id === $this->customer($request)->id ? $subscription : throw new NotFoundHttpException;
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
