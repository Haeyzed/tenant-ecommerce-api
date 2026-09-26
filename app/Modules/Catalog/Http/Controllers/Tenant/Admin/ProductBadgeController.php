<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBadge;
use App\Modules\Catalog\Services\ProductBadgeService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manually assigned badges (spec §29.8).
 */
final class ProductBadgeController extends Controller
{
    public function __construct(private readonly ProductBadgeService $badges) {}

    public function store(Request $request, Product $product): JsonResponse
    {
        $badge = $this->badges->addBadge($product, $request->all());

        return APIResponse::created($badge->only(['id', 'badge_type', 'label']) + [
            'starts_at' => $badge->starts_at?->toIso8601String(),
            'ends_at' => $badge->ends_at?->toIso8601String(),
        ], 'Badge added');
    }

    public function destroy(Product $product, int $badge): JsonResponse
    {
        $this->badges->removeBadge(ProductBadge::query()->where('product_id', $product->id)->whereKey($badge)->firstOrFail());

        return APIResponse::noContent('Badge removed');
    }
}
