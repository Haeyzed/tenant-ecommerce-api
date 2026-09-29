<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use App\Modules\ProductSubscriptions\Http\ProductSubscriptionPresenter;
use App\Modules\ProductSubscriptions\Models\CustomerSubscription;
use App\Modules\ProductSubscriptions\Services\ProductSubscriptionService;
use Illuminate\Support\ServiceProvider;

/**
 * Product subscriptions on the order lifecycle (§55.2): a first order that
 * is cancelled or expires unpaid takes its pending subscription with it.
 * Personal data (§26.4): an anonymised customer's subscriptions stop and
 * their saved payment methods are deleted.
 */
final class ProductSubscriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::CANCELLED, 'product_subscriptions', fn (Order $order) => $this->app->make(ProductSubscriptionService::class)->cancelForOrder($order));
        });

        $this->app->afterResolving(CustomerPrivacyRegistry::class, function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('product_subscriptions', fn (Customer $customer) => $this->app->make(ProductSubscriptionService::class)->eraseForCustomer($customer));
            $privacy->registerSection('product_subscriptions', static fn (Customer $customer): iterable => CustomerSubscription::query()
                ->with(['product:id,name', 'variant:id,sku', 'plan'])->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (CustomerSubscription $s): array => [
                    'product' => $s->product->name,
                    'variant' => $s->variant?->sku,
                    'schedule' => ProductSubscriptionPresenter::label($s->plan),
                    'quantity' => (string) $s->quantity,
                    'status' => $s->status,
                    'next_billing_date' => $s->next_billing_date?->toDateString(),
                    'created_at' => $s->created_at?->toIso8601String(),
                ]));
        });
    }
}
