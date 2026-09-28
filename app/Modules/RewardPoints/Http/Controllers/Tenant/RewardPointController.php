<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\CartResponder;
use App\Modules\Customers\Models\Customer;
use App\Modules\RewardPoints\Models\RewardPointTransaction;
use App\Modules\RewardPoints\Services\RewardPointService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use App\Shared\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's points (spec §54.3) and redeeming them on the cart (§38.7).
 */
final class RewardPointController extends Controller
{
    public function __construct(
        private readonly RewardPointService $points,
        private readonly CartResponder $responder,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        $settings = $this->points->getSettings();

        return APIResponse::success([
            'points_balance' => $this->points->getBalance($this->customer($request)),
            'redeem_amount_per_point' => (string) $settings->redeem_amount_per_point,
            'minimum_redeem_points' => $settings->minimum_redeem_points,
            'maximum_redeem_points_per_order' => $settings->maximum_redeem_points_per_order,
            'program_active' => $this->points->active(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        return APIResponse::success($this->points->getTransactionHistory($this->customer($request))->through(static fn (RewardPointTransaction $t): array => [
            'id' => $t->id,
            'type' => $t->type,
            'points' => $t->points,
            'balance_after' => $t->balance_after,
            'order_id' => $t->order_id,
            'expires_at' => $t->expires_at?->toIso8601String(),
            'notes' => $t->notes,
            'created_at' => $t->created_at?->toIso8601String(),
        ]));
    }

    /**
     * POST /api/cart/apply-reward-points. Body: points (0 removes them).
     * Checked now against the balance and the programme's limits; the
     * quote shows the discount.
     */
    public function applyToCart(Request $request): JsonResponse
    {
        $points = (int) $request->validate(['points' => ['required', 'integer', 'min:0']])['points'];
        $customer = $this->customer($request);
        $cart = $this->responder->findOrFail($request);

        if ($points > 0) {
            $quote = $this->responder->quote($request, $cart);
            // The total before any points already on the cart.
            $before = Money::add((string) $quote->totals['total'], (string) $quote->totals['reward_points_discount_amount']);
            $check = $this->points->validateRedemption($customer, $points, $before, $quote->currency, $quote->exchangeRate);

            if (! $check['valid']) {
                throw ApiException::unprocessable('reward_points_invalid', 'These points cannot be used on this cart.', ['reason' => $check['reason']]);
            }
        }

        $cart->forceFill(['reward_points_to_redeem' => $points === 0 ? null : $points, 'last_activity_at' => now()])->save();

        return $this->responder->respond($request, $cart, $points === 0 ? 'Points removed' : 'Points applied');
    }

    private function customer(Request $request): Customer
    {
        $user = $request->user();

        return $user instanceof Customer ? $user : throw new ApiException('unauthenticated', 'Sign in to use reward points.', 401);
    }
}
