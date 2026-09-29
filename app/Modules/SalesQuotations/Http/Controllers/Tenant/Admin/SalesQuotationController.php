<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\SalesQuotations\Http\SalesQuotationPresenter;
use App\Modules\SalesQuotations\Models\SalesQuotation;
use App\Modules\SalesQuotations\Services\SalesQuotationService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff answering a quote on the customer's behalf (spec §53.3).
 */
final class SalesQuotationController extends Controller
{
    public function __construct(
        private readonly SalesQuotationService $quotations,
        private readonly SalesQuotationPresenter $presenter,
        private readonly OrderPresenter $orders,
    ) {}

    /**
     * Body: address_id? (the customer's; for tax). The order starts pending, order_source admin.
     */
    public function accept(Request $request, SalesQuotation $quotation): JsonResponse
    {
        $addressId = $request->validate(['address_id' => ['sometimes', 'nullable', 'integer']])['address_id'] ?? null;
        /** @var User $user */
        $user = $request->user();
        $order = $this->quotations->acceptQuotation($quotation, 'admin', $user, $addressId === null ? null : (int) $addressId);

        return APIResponse::created($this->orders->order($order->load('items'), true, true), 'Quotation accepted');
    }

    public function reject(SalesQuotation $quotation): JsonResponse
    {
        return APIResponse::success($this->presenter->quotation($this->quotations->rejectQuotation($quotation)->load('items'), true), 'Quotation rejected');
    }
}
