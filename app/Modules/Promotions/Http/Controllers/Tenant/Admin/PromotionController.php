<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Http\PromotionPresenter;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Promotions (spec §37.9).
 */
final class PromotionController extends Controller
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly PromotionPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'trigger' => ['sometimes', Rule::in([Promotion::AUTOMATIC, Promotion::COUPON])],
            'scope' => ['sometimes', Rule::in(Promotion::SCOPES)],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['scheduled', 'running', 'ended'])],
            'seller_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->promotions->listPromotions($filters)->through(fn (Promotion $p): array => $this->presenter->promotion($p, false)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->promotion($this->promotions->createPromotion($request->all(), $this->actor($request)), true), 'Promotion created');
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return APIResponse::success($this->presenter->promotion($promotion->load('targets')->loadCount('coupons'), true, $this->promotions->getUsageStats($promotion)));
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        return APIResponse::success($this->presenter->promotion($this->promotions->updatePromotion($promotion, $request->all()), true), 'Promotion updated');
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $this->promotions->deletePromotion($promotion);

        return APIResponse::noContent('Promotion deleted');
    }

    public function duplicate(Request $request, Promotion $promotion): JsonResponse
    {
        return APIResponse::created($this->presenter->promotion($this->promotions->duplicatePromotion($promotion->load('targets'), $this->actor($request)), true), 'Promotion duplicated');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
