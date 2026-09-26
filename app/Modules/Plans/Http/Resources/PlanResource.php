<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Resources;

use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\PlanLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a plan, including private and inactive data.
 *
 * @mixin Plan
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'tagline' => $this->tagline,
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'is_recommended' => $this->is_recommended,
            'marketing_badge' => $this->marketing_badge,
            'sort_order' => $this->sort_order,
            'prices' => PlanPriceResource::collection($this->whenLoaded('prices')),
            'features' => $this->whenLoaded('features', fn () => $this->features->map(static fn (PlanFeature $f): string => $f->feature_key)->values()->all()),
            'limits' => $this->whenLoaded('limits', fn () => $this->limits->mapWithKeys(static fn (PlanLimit $l): array => [$l->limit_key => $l->limit_value])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
