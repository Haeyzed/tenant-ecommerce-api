<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Promotions\Http\PromotionPresenter;
use App\Modules\Promotions\Models\Coupon;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Services\CouponService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Coupon codes (spec §37.9). generate answers 201 with the count, or 202
 * when a large batch is queued.
 */
final class CouponController extends Controller
{
    public function __construct(
        private readonly CouponService $coupons,
        private readonly PromotionPresenter $presenter,
    ) {}

    public function index(Request $request, Promotion $promotion): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->coupons->listCoupons($promotion, $filters)->through(fn (Coupon $c): array => $this->presenter->coupon($c)));
    }

    public function store(Request $request, Promotion $promotion): JsonResponse
    {
        return APIResponse::created($this->presenter->coupon($this->coupons->createCoupon($promotion, $request->all())), 'Coupon created');
    }

    public function generate(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validate([
            'count' => ['required', 'integer'],
            'prefix' => ['sometimes', 'nullable', 'string'],
            'length' => ['sometimes', 'integer'],
            'usage_limit' => ['sometimes', 'nullable', 'integer'],
        ]);

        $result = $this->coupons->generateCoupons($promotion, (int) $validated['count'], array_intersect_key($validated, array_flip(['prefix', 'length', 'usage_limit'])));

        return $result['queued']
            ? APIResponse::success($result, 'Coupon generation queued', [], 202)
            : APIResponse::created($result, 'Coupons generated');
    }

    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        return APIResponse::success(['results' => $this->coupons->bulk($validated['action'], $validated['ids'])]);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        return APIResponse::success($this->presenter->coupon($this->coupons->updateCoupon($coupon, $request->all())), 'Coupon updated');
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $this->coupons->deleteCoupon($coupon);

        return APIResponse::noContent('Coupon deleted');
    }
}
