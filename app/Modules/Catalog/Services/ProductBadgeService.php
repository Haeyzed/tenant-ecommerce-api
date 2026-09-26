<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBadge;
use App\Modules\Catalog\Support\ProductPricing;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Product badges (spec §29.5): manual badges in their window plus the
 * computed "on_sale" and "new" (Assumption A-12). A computed badge is not
 * added when a manual badge of the same type is live.
 */
final readonly class ProductBadgeService
{
    public const int NEW_DAYS = 30;

    public function __construct(private ProductPricing $pricing) {}

    /**
     * @return Collection<int, array{type: string, label: string|null, computed: bool}>
     */
    public function getBadgesForProduct(Product $product): Collection
    {
        $badges = $product->badges->filter(static fn (ProductBadge $b): bool => $b->isLive())
            ->map(static fn (ProductBadge $b): array => ['type' => $b->badge_type, 'label' => $b->label, 'computed' => false])
            ->values();

        $types = $badges->pluck('type')->all();

        if (! in_array('on_sale', $types, true) && $this->pricing->isOnSale($product)) {
            $badges->push(['type' => 'on_sale', 'label' => null, 'computed' => true]);
        }

        if (! in_array('new', $types, true) && $product->created_at->gte(now()->subDays(self::NEW_DAYS))) {
            $badges->push(['type' => 'new', 'label' => null, 'computed' => true]);
        }

        return $badges;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addBadge(Product $product, array $data): ProductBadge
    {
        $validated = validator($data, [
            'badge_type' => ['required', Rule::in(ProductBadge::TYPES)],
            'label' => ['required_if:badge_type,custom', 'nullable', 'string', 'max:60'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ])->validate();

        /** @var ProductBadge $badge */
        $badge = $product->badges()->create($validated);

        return $badge;
    }

    public function removeBadge(ProductBadge $badge): void
    {
        $badge->delete();
    }
}
