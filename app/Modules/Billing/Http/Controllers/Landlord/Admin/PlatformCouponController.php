<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Http\Resources\PlatformCouponResource;
use App\Modules\Billing\Metrics\CouponMetrics;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Services\PlatformCouponService;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformCouponController extends Controller
{
    public function __construct(private readonly PlatformCouponService $coupons) {}

    /**
     * The list screen's KPI strip (spec §22.4).
     */
    public function metrics(MetricsRangeRequest $request, PlatformDashboardService $dashboard, CouponMetrics $metrics): JsonResponse
    {
        $result = $dashboard->contextualKpis('platform-coupons', $dashboard->range($request->rangeInput()), $metrics->contextual(...));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:scheduled,running,ended'],
            'affiliate_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PlatformCoupon::query()
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($filters['affiliate_id'] ?? null, static fn ($q, $v) => $q->where('affiliate_id', $v))
            ->when(($filters['status'] ?? null) === 'scheduled', static fn ($q) => $q->where('starts_at', '>', now()))
            ->when(($filters['status'] ?? null) === 'running', static fn ($q) => $q
                ->where(static fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(static fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now())))
            ->when(($filters['status'] ?? null) === 'ended', static fn ($q) => $q->where('ends_at', '<=', now()))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(PlatformCouponResource::collection($page));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(new PlatformCouponResource($this->coupons->create($request->all(), $this->user($request))));
    }

    public function show(PlatformCoupon $coupon): JsonResponse
    {
        return APIResponse::success([
            'coupon' => new PlatformCouponResource($coupon->load('targets')),
            'usage' => $this->coupons->usageStats($coupon),
        ]);
    }

    public function update(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        return APIResponse::success(new PlatformCouponResource($this->coupons->update($coupon, $request->all(), $this->user($request))), 'Coupon updated');
    }

    public function deactivate(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        return APIResponse::success(new PlatformCouponResource($this->coupons->deactivate($coupon, $this->user($request))), 'Coupon deactivated');
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
