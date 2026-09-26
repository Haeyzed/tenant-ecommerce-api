<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Http\PromotionPresenter;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/promotions/{promotion}/redemptions (spec §37.9).
 */
final class PromotionRedemptionController extends Controller
{
    public function index(Request $request, Promotion $promotion, PromotionPresenter $presenter): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([PromotionRedemption::RESERVED, PromotionRedemption::COMMITTED, PromotionRedemption::RELEASED, PromotionRedemption::REVERSED])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $promotion->redemptions()
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success($page->through(static fn (PromotionRedemption $r): array => $presenter->redemption($r)));
    }
}
