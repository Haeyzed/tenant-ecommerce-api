<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Models\PlatformCouponRedemption;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformCouponRedemptionController extends Controller
{
    public function index(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        $page = PlatformCouponRedemption::query()
            ->where('platform_coupon_id', $coupon->id)
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))
            ->through(static fn (PlatformCouponRedemption $r): array => [
                'id' => $r->id,
                'tenant_id' => $r->tenant_id,
                'subscription_id' => $r->subscription_id,
                'owner_email' => $r->owner_email,
                'status' => $r->status,
                'cycles_total' => $r->cycles_total,
                'cycles_applied' => $r->cycles_applied,
                'total_discount_amount' => (string) $r->total_discount_amount,
                'reserved_at' => $r->reserved_at->toIso8601String(),
                'activated_at' => $r->activated_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'released_at' => $r->released_at?->toIso8601String(),
            ]);

        return APIResponse::success($page);
    }
}
