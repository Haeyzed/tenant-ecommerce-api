<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Services\SellerProductModerationService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Seller product moderation (spec §50.3, §50.7).
 */
final class SellerProductModerationController extends Controller
{
    public function __construct(
        private readonly SellerProductModerationService $moderation,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'moderation_status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])],
            'seller_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->moderation->listPending($filters);
        $page->getCollection()->load(['brand:id,name', 'categories:id,name', 'media']);

        return APIResponse::success($page->through(fn (Product $p): array => $this->presenter->product($p, false)));
    }

    public function approve(Product $product): JsonResponse
    {
        return APIResponse::success($this->presenter->product($this->moderation->approve($product)->load(['brand', 'categories', 'media', 'seller']), false), 'Product approved');
    }

    /**
     * Body: moderation_note.
     */
    public function reject(Request $request, Product $product): JsonResponse
    {
        $product = $this->moderation->reject($product, (string) $request->input('moderation_note', ''));

        return APIResponse::success($this->presenter->product($product->load(['brand', 'categories', 'media', 'seller']), false), 'Product rejected');
    }
}
