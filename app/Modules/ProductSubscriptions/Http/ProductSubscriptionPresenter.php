<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Http;

use App\Modules\ProductSubscriptions\Models\CustomerSubscription;
use App\Modules\ProductSubscriptions\Models\CustomerSubscriptionOrder;
use App\Modules\ProductSubscriptions\Models\ProductSubscriptionPlan;

/**
 * Subscription and plan payloads (spec §55). The stored payment method is
 * never shown, only whether one is saved.
 */
final class ProductSubscriptionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function plan(ProductSubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'product_id' => $plan->product_id,
            'interval' => $plan->interval,
            'interval_count' => $plan->interval_count,
            'label' => self::label($plan),
            'is_active' => $plan->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function subscription(CustomerSubscription $subscription, bool $admin): array
    {
        $payload = [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'product' => $subscription->relationLoaded('product') ? [
                'id' => $subscription->product->id,
                'name' => $subscription->product->name,
                'slug' => $subscription->product->slug,
                'sku' => $subscription->product->sku,
            ] : ['id' => $subscription->product_id],
            'variant' => $subscription->relationLoaded('variant') && $subscription->variant !== null
                ? ['id' => $subscription->variant->id, 'sku' => $subscription->variant->sku] : null,
            'plan' => $subscription->relationLoaded('plan') ? $this->plan($subscription->plan) : ['id' => $subscription->product_subscription_plan_id],
            'quantity' => (string) $subscription->quantity,
            'discount_percent' => $subscription->relationLoaded('product') && $subscription->product->subscription_discount_percent !== null
                ? (string) $subscription->product->subscription_discount_percent : null,
            'currency_code' => $subscription->currency_code,
            'address_id' => $subscription->address_id,
            'shipping_method_id' => $subscription->shipping_method_id,
            'next_billing_date' => $subscription->next_billing_date?->toDateString(),
            'failed_renewal_count' => $subscription->failed_renewal_count,
            'has_stored_payment_method' => $subscription->payment_method_token !== null,
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];

        if ($subscription->relationLoaded('subscriptionOrders')) {
            $payload['orders'] = $subscription->subscriptionOrders->map(static fn (CustomerSubscriptionOrder $link): array => [
                'order_id' => $link->order_id,
                'order_number' => $link->order?->order_number,
                'is_renewal' => $link->is_renewal,
                'billing_date' => $link->billing_date?->toDateString(),
                'status' => $link->order?->status,
                'payment_status' => $link->order?->payment_status,
                'total' => $link->order === null ? null : (string) $link->order->total,
                'currency_code' => $link->order?->currency_code,
            ])->all();
        }

        if ($admin) {
            $payload['customer'] = $subscription->relationLoaded('customer') && $subscription->customer !== null
                ? ['id' => $subscription->customer->id, 'name' => $subscription->customer->name, 'email' => $subscription->customer->email] : null;
            $payload['payment_provider'] = $subscription->payment_provider;
            $payload['last_renewal_error'] = $subscription->last_renewal_error;
        }

        return $payload;
    }

    /**
     * "Every week", "Every 2 months", "Every quarter".
     */
    public static function label(ProductSubscriptionPlan $plan): string
    {
        [$one, $many] = match ($plan->interval) {
            'weekly' => ['week', 'weeks'],
            'biweekly' => ['2 weeks', null],
            'quarterly' => ['quarter', 'quarters'],
            default => ['month', 'months'],
        };

        if ($plan->interval === 'biweekly') {
            return $plan->interval_count === 1 ? 'Every 2 weeks' : 'Every '.(2 * $plan->interval_count).' weeks';
        }

        return $plan->interval_count === 1 ? "Every {$one}" : "Every {$plan->interval_count} {$many}";
    }
}
