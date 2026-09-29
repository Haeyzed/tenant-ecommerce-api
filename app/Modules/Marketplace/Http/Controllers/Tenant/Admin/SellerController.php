<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerGroup;
use App\Modules\Marketplace\Services\SellerLedgerService;
use App\Modules\Marketplace\Services\SellerService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sellers in the back office (spec §50.7).
 */
final class SellerController extends Controller
{
    public function __construct(
        private readonly SellerService $sellers,
        private readonly SellerLedgerService $ledger,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Seller::STATUSES)],
            'seller_group_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->sellers->listSellers($filters)->through(fn (Seller $s): array => $this->presenter->sellerForAdmin($s)));
    }

    public function show(Seller $seller): JsonResponse
    {
        return APIResponse::success([...$this->presenter->sellerForAdmin($seller->loadCount('products'), true), 'balance' => $this->ledger->getUnpaidBalance($seller)]);
    }

    public function approve(Seller $seller): JsonResponse
    {
        return APIResponse::success($this->presenter->sellerForAdmin($this->sellers->approveSeller($seller)), 'Seller approved');
    }

    /**
     * Body: reason.
     */
    public function reject(Request $request, Seller $seller): JsonResponse
    {
        return APIResponse::success($this->presenter->sellerForAdmin($this->sellers->rejectSeller($seller, (string) $request->input('reason', ''))), 'Seller rejected');
    }

    public function suspend(Seller $seller): JsonResponse
    {
        return APIResponse::success($this->presenter->sellerForAdmin($this->sellers->suspendSeller($seller)), 'Seller suspended');
    }

    /**
     * Body: commission_rate (null = the group's, else the store default).
     */
    public function commissionRate(Request $request, Seller $seller): JsonResponse
    {
        $rate = $request->validate(['commission_rate' => ['present', 'nullable', 'numeric']])['commission_rate'];

        return APIResponse::success($this->presenter->sellerForAdmin($this->sellers->updateCommissionRate($seller, $rate === null ? null : (string) $rate)), 'Commission rate updated');
    }

    /**
     * Body: seller_group_id (null removes the seller from its group).
     */
    public function assignGroup(Request $request, Seller $seller): JsonResponse
    {
        $groupId = $request->validate(['seller_group_id' => ['present', 'nullable', 'integer', Rule::exists('tenant.seller_groups', 'id')]])['seller_group_id'];
        $seller = $this->sellers->assignToGroup($seller, $groupId === null ? null : SellerGroup::query()->findOrFail($groupId));

        return APIResponse::success($this->presenter->sellerForAdmin($seller->refresh()), 'Group assigned');
    }
}
