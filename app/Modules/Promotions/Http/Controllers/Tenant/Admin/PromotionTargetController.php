<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Http\PromotionPresenter;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Services\PromotionService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUT /api/admin/promotions/{promotion}/targets (spec §37.9): replaces the
 * whole target set (promotions.targets).
 */
final class PromotionTargetController extends Controller
{
    public function sync(Request $request, Promotion $promotion, PromotionService $promotions, PromotionPresenter $presenter): JsonResponse
    {
        $targets = $request->validate(['targets' => ['present', 'array']])['targets'];

        return APIResponse::success($presenter->promotion($promotions->syncTargets($promotion, $targets), true), 'Targets updated');
    }
}
