<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\PlatformCouponService;
use App\Modules\Plans\Models\PlanPrice;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public coupon check for the pricing and sign-up pages (spec §14.8).
 */
final class PlatformCouponController extends Controller
{
    public function __construct(private readonly PlatformCouponService $coupons) {}

    public function validate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'plan_price_id' => ['required', 'integer'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
        ]);

        $price = PlanPrice::query()->where('is_active', true)->whereHas('plan', static fn ($q) => $q->where('is_active', true)->where('is_public', true))
            ->findOrFail((int) $validated['plan_price_id']);

        $result = $this->coupons->validateForRegistration($validated['code'], $price, (string) ($validated['email'] ?? ''));

        return APIResponse::success([
            'valid' => $result['valid'],
            'reason' => $result['reason'],
            'discount' => $result['discount'],
            'currency_code' => $result['currency_code'],
            'description' => $result['coupon']?->description,
            'duration' => $result['coupon']?->duration,
            'duration_cycles' => $result['coupon']?->cycles(),
        ]);
    }
}
