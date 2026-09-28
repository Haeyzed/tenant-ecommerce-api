<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\RewardPoints\Models\RewardPointSettings;
use App\Modules\RewardPoints\Models\RewardPointTransaction;
use App\Modules\RewardPoints\Services\RewardPointService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The loyalty programme's settings and customers' points (spec §54.3).
 */
final class RewardPointAdminController extends Controller
{
    public function __construct(private readonly RewardPointService $points) {}

    public function settings(): JsonResponse
    {
        return APIResponse::success($this->present($this->points->getSettings()));
    }

    /**
     * Body: is_active?, amount_per_point?, minimum_order_amount_to_earn?, point_expiry_days?,
     * redeem_amount_per_point?, minimum_order_total_to_redeem?, minimum_redeem_points?, maximum_redeem_points_per_order?
     */
    public function updateSettings(Request $request): JsonResponse
    {
        return APIResponse::success($this->present($this->points->updateSettings($request->all())), 'Reward point settings updated');
    }

    public function customer(Customer $customer): JsonResponse
    {
        return APIResponse::success([
            'customer_id' => $customer->id,
            'points_balance' => $this->points->getBalance($customer),
            'history' => $this->points->getTransactionHistory($customer, 50)->getCollection()->map(static fn (RewardPointTransaction $t): array => [
                'id' => $t->id, 'type' => $t->type, 'points' => $t->points, 'balance_after' => $t->balance_after,
                'order_id' => $t->order_id, 'notes' => $t->notes, 'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Body: points (negative removes), reason.
     */
    public function adjust(Request $request, Customer $customer): JsonResponse
    {
        $balance = $this->points->adjustPoints($customer, (int) $request->input('points'), (string) $request->input('reason', ''));

        return APIResponse::success(['customer_id' => $customer->id, 'points_balance' => $balance], 'Points adjusted');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RewardPointSettings $s): array
    {
        return [
            'is_active' => $s->is_active,
            'amount_per_point' => (string) $s->amount_per_point,
            'minimum_order_amount_to_earn' => $s->minimum_order_amount_to_earn === null ? null : (string) $s->minimum_order_amount_to_earn,
            'point_expiry_days' => $s->point_expiry_days,
            'redeem_amount_per_point' => (string) $s->redeem_amount_per_point,
            'minimum_order_total_to_redeem' => $s->minimum_order_total_to_redeem === null ? null : (string) $s->minimum_order_total_to_redeem,
            'minimum_redeem_points' => $s->minimum_redeem_points,
            'maximum_redeem_points_per_order' => $s->maximum_redeem_points_per_order,
        ];
    }
}
