<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Resources;

use App\Modules\Affiliates\Models\AffiliatePayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The decrypted payout-details snapshot is included only when the caller
 * asks for it (billing admins on the payout being paid, §75 rule 31).
 *
 * @mixin AffiliatePayout
 */
final class AffiliatePayoutResource extends JsonResource
{
    public bool $withDetails = false;

    public bool $portal = false;

    public function withPayoutDetails(): self
    {
        $this->withDetails = true;

        return $this;
    }

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
            'id' => $this->when(! $this->portal, $this->id),
            'reference' => $this->reference,
            'affiliate' => $this->when(! $this->portal && $this->relationLoaded('affiliate'), fn (): array => [
                'id' => $this->affiliate->id,
                'name' => $this->affiliate->name,
            ]),
            'currency_code' => $this->currency_code,
            'amount' => (string) $this->amount,
            'commission_count' => $this->commission_count,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status,
            'payout_method' => $this->payout_method,
            'payout_details' => $this->when($this->withDetails, fn (): array => (array) $this->payout_details_snapshot),
            'external_reference' => $this->external_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'commissions' => $this->when($this->relationLoaded('commissions'), fn (): array => $this->commissions
                ->map(fn ($c): array => ($this->portal ? (new AffiliateCommissionResource($c))->forPortal() : new AffiliateCommissionResource($c))->toArray($request))
                ->all()),
        ];
    }
}
