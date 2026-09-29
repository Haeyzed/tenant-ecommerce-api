<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerPayout;
use App\Modules\Marketplace\Services\SellerPayoutService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Seller payouts (spec §50.5, §50.7). Generating and marking paid stay
 * available while the module winds down.
 */
final class SellerPayoutController extends Controller
{
    public function __construct(
        private readonly SellerPayoutService $payouts,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(Seller $seller): JsonResponse
    {
        return APIResponse::success($this->payouts->listPayouts($seller)->map(fn (SellerPayout $p): array => $this->presenter->payout($p))->values());
    }

    /**
     * Body: from, to (Y-m-d), preview? (true: the totals only, nothing saved).
     */
    public function store(Request $request, Seller $seller): JsonResponse
    {
        $from = (string) $request->input('from', '');
        $to = (string) $request->input('to', '');

        if ($request->boolean('preview')) {
            return APIResponse::success($this->payouts->calculatePayout($seller, $from, $to));
        }

        /** @var User $user */
        $user = $request->user();

        return APIResponse::created($this->presenter->payout($this->payouts->generatePayout($seller, $from, $to, $user)), 'Payout generated');
    }

    /**
     * Body: reference?, notes?
     */
    public function markPaid(Request $request, Seller $seller, SellerPayout $payout): JsonResponse
    {
        if ($payout->seller_id !== $seller->id) {
            throw new NotFoundHttpException;
        }

        $payout = $this->payouts->markPaid($payout, $request->input('reference'), $request->input('notes'));

        return APIResponse::success($this->presenter->payout($payout), 'Payout marked paid');
    }
}
