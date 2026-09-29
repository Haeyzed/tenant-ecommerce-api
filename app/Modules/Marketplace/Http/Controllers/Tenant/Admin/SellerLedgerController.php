<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerLedgerEntry;
use App\Modules\Marketplace\Services\SellerLedgerService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A seller's ledger (spec §50.4, §50.7).
 */
final class SellerLedgerController extends Controller
{
    public function __construct(
        private readonly SellerLedgerService $ledger,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(Request $request, Seller $seller): JsonResponse
    {
        $filters = $request->validate([
            'entry_type' => ['sometimes', Rule::in([SellerLedgerEntry::SALE, SellerLedgerEntry::REVERSAL])],
            'order_id' => ['sometimes', 'integer'],
            'unpaid' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (isset($filters['unpaid'])) {
            $filters['unpaid'] = (bool) $filters['unpaid'];
        }

        return APIResponse::success($this->ledger->listEntries($seller, $filters)->through(fn (SellerLedgerEntry $e): array => $this->presenter->entry($e)));
    }
}
