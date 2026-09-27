<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\SupplierQuotation;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\QuotationService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One supplier's answer (spec §49.3, §49.7).
 */
final class SupplierQuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly PurchaseOrderService $orders,
        private readonly PurchasingPresenter $presenter,
    ) {}

    /**
     * Body: items[{quotation_request_item_id, unit_price, lead_time_days?}], valid_until?, notes?
     */
    public function record(Request $request, SupplierQuotation $quotation): JsonResponse
    {
        $validUntil = $request->validate(['valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d']])['valid_until'] ?? null;
        $this->quotations->recordSupplierQuote($quotation, array_values((array) $request->input('items', [])),
            $validUntil === null ? null : Carbon::parse($validUntil), $request->input('notes'));

        return APIResponse::success($this->presenter->supplierQuotation($quotation->load(['supplier:id,name', 'items'])), 'Quotation recorded');
    }

    /**
     * Creates the draft purchase order and rejects the other quotations.
     */
    public function accept(Request $request, SupplierQuotation $quotation): JsonResponse
    {
        /** @var User $by */
        $by = $request->user();
        $order = $this->quotations->acceptQuotation($quotation, $by);

        return APIResponse::success([
            'quotation' => $this->presenter->supplierQuotation($quotation->load(['supplier:id,name', 'items'])),
            'purchase_order' => $this->presenter->purchaseOrder($this->orders->getPurchaseOrder($order), true),
        ], 'Quotation accepted');
    }

    public function reject(SupplierQuotation $quotation): JsonResponse
    {
        $this->quotations->rejectQuotation($quotation);

        return APIResponse::success($this->presenter->supplierQuotation($quotation->load(['supplier:id,name', 'items'])), 'Quotation rejected');
    }
}
