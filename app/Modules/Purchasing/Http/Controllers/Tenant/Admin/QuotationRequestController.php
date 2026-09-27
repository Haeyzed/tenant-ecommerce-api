<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\QuotationRequest;
use App\Modules\Purchasing\Services\QuotationService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Requests for quotation (spec §49.3, §49.7).
 */
final class QuotationRequestController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly PurchasingPresenter $presenter,
    ) {}

    public function index(Request $http): JsonResponse
    {
        $filters = $http->validate([
            'status' => ['sometimes', Rule::in(QuotationRequest::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->quotations->listRequests($filters)->through(fn (QuotationRequest $r): array => $this->presenter->quotationRequest($r)));
    }

    /**
     * Body: warehouse_id, items[{product_id, product_variant_id?, quantity}], notes?, respond_by?
     */
    public function store(Request $http): JsonResponse
    {
        $validated = $http->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'items' => ['required', 'array'],
        ]);

        /** @var User $by */
        $by = $http->user();
        $request = $this->quotations->createRequest(Warehouse::query()->findOrFail($validated['warehouse_id']), array_values((array) $validated['items']), $by, $http->only(['notes', 'respond_by']));

        return APIResponse::created($this->presenter->quotationRequest($this->quotations->getRequest($request), true), 'Quotation request created');
    }

    public function show(QuotationRequest $request): JsonResponse
    {
        return APIResponse::success($this->presenter->quotationRequest($this->quotations->getRequest($request), true));
    }

    /**
     * Body: supplier_ids[]. Each supplier gets a pending quotation and an email.
     */
    public function send(Request $http, QuotationRequest $request): JsonResponse
    {
        $this->quotations->sendToSuppliers($request, array_map('intval', (array) $http->input('supplier_ids', [])));

        return APIResponse::success($this->presenter->quotationRequest($this->quotations->getRequest($request->refresh()), true), 'Quotation request sent');
    }

    public function cancel(QuotationRequest $request): JsonResponse
    {
        $this->quotations->cancelRequest($request);

        return APIResponse::success($this->presenter->quotationRequest($this->quotations->getRequest($request), true), 'Quotation request cancelled');
    }
}
