<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\SalesQuotations\Http\SalesQuotationPresenter;
use App\Modules\SalesQuotations\Models\SalesQuotationRequest;
use App\Modules\SalesQuotations\Services\SalesQuotationService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Quotation requests in the back office (spec §53.3).
 */
final class SalesQuotationRequestController extends Controller
{
    public function __construct(
        private readonly SalesQuotationService $quotations,
        private readonly SalesQuotationPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(SalesQuotationRequest::STATUSES)],
            'customer_id' => ['sometimes', 'integer'],
            'sales_agent_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:20'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->quotations->listRequests($filters)->through(fn (SalesQuotationRequest $r): array => $this->presenter->request($r, true, false)));
    }

    /**
     * Body: customer_id? (null = a walk-in or phone enquiry), items[{product_id, variant_id?, quantity}],
     * notes?, currency_code?, sales_agent_id?, draft?
     */
    public function store(Request $request): JsonResponse
    {
        $customerId = $request->validate(['customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.customers', 'id')->whereNull('deleted_at')]])['customer_id'] ?? null;
        $created = $this->quotations->createRequest($customerId === null ? null : Customer::query()->findOrFail($customerId),
            $request->only(['items', 'notes', 'currency_code', 'sales_agent_id', 'draft']), $this->user($request));

        return APIResponse::created($this->presenter->request($created, true), 'Quotation request created');
    }

    public function show(SalesQuotationRequest $quotationRequest): JsonResponse
    {
        return APIResponse::success($this->presenter->request($this->quotations->getRequest($quotationRequest), true));
    }

    /**
     * Body: items[{request_item_id, unit_price, discount_amount?}], valid_until?, notes?, custom_fields?
     */
    public function send(Request $request, SalesQuotationRequest $quotationRequest): JsonResponse
    {
        $this->quotations->sendQuotation($quotationRequest, $request->all(), $this->user($request));

        return APIResponse::success($this->presenter->request($this->quotations->getRequest($quotationRequest->refresh()), true), 'Quotation sent');
    }

    public function cancel(SalesQuotationRequest $quotationRequest): JsonResponse
    {
        $this->quotations->cancelRequest($quotationRequest);

        return APIResponse::success($this->presenter->request($this->quotations->getRequest($quotationRequest->refresh()), true), 'Quotation request cancelled');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
