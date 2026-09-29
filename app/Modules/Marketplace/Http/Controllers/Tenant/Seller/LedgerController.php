<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerLedgerEntry;
use App\Modules\Marketplace\Models\SellerPayout;
use App\Modules\Marketplace\Services\SellerLedgerService;
use App\Modules\Marketplace\Services\SellerPayoutService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The seller's ledger and payouts (spec §50.7), read-only.
 */
final class LedgerController extends Controller
{
    public function __construct(
        private readonly SellerLedgerService $ledger,
        private readonly SellerPayoutService $payouts,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entry_type' => ['sometimes', Rule::in([SellerLedgerEntry::SALE, SellerLedgerEntry::REVERSAL])],
            'unpaid' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (isset($filters['unpaid'])) {
            $filters['unpaid'] = (bool) $filters['unpaid'];
        }

        return APIResponse::success($this->ledger->listEntries($this->seller($request), $filters)->through(fn (SellerLedgerEntry $e): array => $this->presenter->entry($e)));
    }

    public function payouts(Request $request): JsonResponse
    {
        return APIResponse::success($this->payouts->listPayouts($this->seller($request))->map(fn (SellerPayout $p): array => $this->presenter->payout($p))->values());
    }

    private function seller(Request $request): Seller
    {
        /** @var Seller */
        return $request->user();
    }
}
