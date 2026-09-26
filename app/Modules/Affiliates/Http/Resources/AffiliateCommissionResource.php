<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Resources;

use App\Modules\Affiliates\Models\AffiliateCommission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AffiliateCommission
 */
final class AffiliateCommissionResource extends JsonResource
{
    public bool $portal = false;

    public function forPortal(): self
    {
        $this->portal = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'affiliate' => $this->when(! $this->portal && $this->relationLoaded('affiliate'), fn (): array => [
                'id' => $this->affiliate->id,
                'name' => $this->affiliate->name,
            ]),
            'affiliate_referral_id' => $this->affiliate_referral_id,
            'tenant_id' => $this->when(! $this->portal, $this->tenant_id),
            'payment_transaction_id' => $this->when(! $this->portal, $this->payment_transaction_id),
            'reverses_commission_id' => $this->reverses_commission_id,
            'base_amount' => (string) $this->base_amount,
            'currency_code' => $this->currency_code,
            'commission_rate_applied' => (string) $this->commission_rate_applied,
            'amount' => (string) $this->amount,
            'original_amount' => $this->original_amount !== null ? (string) $this->original_amount : null,
            'status' => $this->status,
            'is_eligible' => $this->status === AffiliateCommission::PENDING && ! $this->requires_review && $this->hold_until->isPast(),
            'is_payable' => $this->status === AffiliateCommission::APPROVED && $this->affiliate_payout_id === null && ! $this->requires_review,
            'requires_review' => $this->when(! $this->portal, $this->requires_review),
            'hold_until' => $this->hold_until->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'affiliate_payout_id' => $this->affiliate_payout_id,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
