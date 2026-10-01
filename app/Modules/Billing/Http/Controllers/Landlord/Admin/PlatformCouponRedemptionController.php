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
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PlatformCouponRedemption::query()
            ->with('tenant:id,name,slug')
            ->where('platform_coupon_id', $coupon->id)
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->through(static fn (PlatformCouponRedemption $r): array => [
                'id' => $r->id,
                'tenant_id' => $r->tenant_id,
                'tenant_name' => $r->tenant?->name,
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
