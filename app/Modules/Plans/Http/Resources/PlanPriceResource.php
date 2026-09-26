<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Resources;

use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlanPrice
 */
final class PlanPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'currency_code' => $this->currency_code,
            'billing_interval' => $this->billing_interval,
            'amount' => (string) $this->amount,
            'trial_days' => $this->trial_days,
            'resolved_trial_days' => app(PlanService::class)->resolveTrialDays($this->resource),
            'trial_requires_payment_method' => $this->trial_requires_payment_method,
            'is_active' => $this->is_active,
            'gateway_references' => $this->gateway_references ?? (object) [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
