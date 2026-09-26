<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Resources;

use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Services\AffiliateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An affiliate as platform staff see it, or (forPortal) as the affiliate
 * sees itself. Payout details are always masked.
 *
 * @mixin Affiliate
 */
final class AffiliateResource extends JsonResource
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
        $service = app(AffiliateService::class);

        return [
            'id' => $this->when(! $this->portal, $this->id),
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'phone' => $this->phone,
            'company_name' => $this->company_name,
            'website_url' => $this->website_url,
            'country_id' => $this->country_id,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'rejection_reason' => $this->rejection_reason,
            'referral_code' => $this->portal && ! $this->isApproved() ? null : $this->referral_code,
            'referral_link' => $this->referralLink(),
            'commission_rate' => $this->when(! $this->portal, fn (): ?string => $this->commission_rate !== null ? (string) $this->commission_rate : null),
            'effective_commission_rate' => $service->effectiveRate($this->resource),
            'promotion_methods' => $this->when(! $this->portal, $this->promotion_methods),
            'payout' => $service->maskedPayoutDetails($this->resource),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'last_login_at' => $this->when(! $this->portal, fn (): ?string => $this->last_login_at?->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
