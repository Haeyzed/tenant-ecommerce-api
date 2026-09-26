<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Resources;

use App\Modules\Affiliates\Models\AffiliateReferral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A referral. The affiliate sees the referred business only as a masked
 * name, never its contact details (§21A.8).
 *
 * @mixin AffiliateReferral
 */
final class AffiliateReferralResource extends JsonResource
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
        $name = (string) ($this->tenant?->name ?? '');

        return [
            'id' => $this->id,
            'business' => $this->portal ? self::mask($name) : $name,
            'tenant_id' => $this->when(! $this->portal, $this->tenant_id),
            'affiliate' => $this->when(! $this->portal && $this->relationLoaded('affiliate'), fn (): array => [
                'id' => $this->affiliate->id,
                'name' => $this->affiliate->name,
            ]),
            'source' => $this->source,
            'status' => $this->status,
            'ineligible_reason' => $this->ineligible_reason,
            'requires_review' => $this->when(! $this->portal, $this->requires_review),
            'risk_flags' => $this->when(! $this->portal, fn (): array => (array) $this->risk_flags),
            'attributed_at' => $this->attributed_at->toIso8601String(),
            'conversion_deadline' => $this->conversion_deadline?->toIso8601String(),
            'converted_at' => $this->converted_at?->toIso8601String(),
        ];
    }

    /**
     * "Acme Stores" becomes "Ac*** St***".
     */
    public static function mask(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];

        return implode(' ', array_map(static fn (string $w): string => mb_substr($w, 0, 2).'***', array_filter($words, static fn (string $w): bool => $w !== '')));
    }
}
