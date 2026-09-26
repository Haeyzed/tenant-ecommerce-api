<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Models\PlatformCouponTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformCoupon
 */
final class PlatformCouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => (string) $this->discount_value,
            'currency_code' => $this->currency_code,
            'duration' => $this->duration,
            'duration_cycles' => $this->duration_cycles,
            'max_discount_amount' => $this->max_discount_amount !== null ? (string) $this->max_discount_amount : null,
            'min_amount' => $this->min_amount !== null ? (string) $this->min_amount : null,
            'first_subscription_only' => $this->first_subscription_only,
            'usage_limit_total' => $this->usage_limit_total,
            'usage_limit_per_tenant' => $this->usage_limit_per_tenant,
            'times_redeemed' => $this->times_redeemed,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'affiliate_id' => $this->affiliate_id,
            'is_active' => $this->is_active,
            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(
                static fn (PlatformCouponTarget $t): array => ['target_type' => $t->target_type, 'target_id' => $t->target_id],
            )->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
