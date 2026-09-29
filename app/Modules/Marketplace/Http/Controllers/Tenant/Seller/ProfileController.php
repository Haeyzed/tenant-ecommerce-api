<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Services\SellerLedgerService;
use App\Modules\Marketplace\Services\SellerService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The seller's own profile (spec §50.7): the effective rate and group are
 * read-only, with the unpaid balance.
 */
final class ProfileController extends Controller
{
    public function __construct(
        private readonly SellerService $sellers,
        private readonly SellerLedgerService $ledger,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return APIResponse::success([...$this->presenter->sellerForSelf($seller), 'balance' => $this->ledger->getUnpaidBalance($seller)]);
    }

    /**
     * Body: business_name?, contact_name?, phone?
     */
    public function update(Request $request): JsonResponse
    {
        $seller = $this->sellers->updateProfile($this->seller($request), $request->only(['business_name', 'contact_name', 'phone']));

        return APIResponse::success($this->presenter->sellerForSelf($seller), 'Profile updated');
    }

    private function seller(Request $request): Seller
    {
        /** @var Seller */
        return $request->user();
    }
}
