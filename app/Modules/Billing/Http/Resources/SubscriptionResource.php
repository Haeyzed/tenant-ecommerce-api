<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
final class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'plan' => $this->whenLoaded('plan', fn (): array => ['id' => $this->plan->id, 'name' => $this->plan->name, 'slug' => $this->plan->slug]),
            'plan_price_id' => $this->plan_price_id,
            'currency_code' => $this->currency_code,
            'billing_interval' => $this->billing_interval,
            'gateway' => $this->gateway,
            'gateway_mode' => $this->gateway_mode,
            'has_payment_method' => $this->authorization_reference !== null,
            'status' => $this->status->value,
            'trial_days' => $this->trial_days,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'renews_at' => $this->renews_at?->toIso8601String(),
            'past_due_at' => $this->past_due_at?->toIso8601String(),
            'scheduled_plan_id' => $this->scheduled_plan_id,
            'scheduled_plan_price_id' => $this->scheduled_plan_price_id,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
