<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\SalesQuotations\Http\SalesQuotationPresenter;
use App\Modules\SalesQuotations\Models\SalesQuotation;
use App\Modules\SalesQuotations\Models\SalesQuotationRequest;
use App\Modules\SalesQuotations\Services\SalesQuotationService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer's own quotation requests (spec §53.3). Another customer's
 * request is a 404.
 */
final class QuotationRequestController extends Controller
{
    public function __construct(
        private readonly SalesQuotationService $quotations,
        private readonly SalesQuotationPresenter $presenter,
        private readonly OrderPresenter $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(SalesQuotationRequest::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return APIResponse::success($this->quotations->listRequests([...$filters, 'customer_id' => $this->customer($request)->id])
            ->through(fn (SalesQuotationRequest $r): array => $this->presenter->request($r, false, false)));
    }

    /**
     * Body: items[{product_id, variant_id?, quantity}], notes?, currency_code?, sales_agent_code?
     */
    public function store(Request $request): JsonResponse
    {
        $created = $this->quotations->createRequest($this->customer($request), $request->only(['items', 'notes', 'currency_code', 'sales_agent_code']));

        return APIResponse::created($this->presenter->request($created, false), 'Quotation requested');
    }

    public function show(Request $request, SalesQuotationRequest $quotationRequest): JsonResponse
    {
        return APIResponse::success($this->presenter->request($this->quotations->getRequest($this->own($request, $quotationRequest)), false));
    }

    /**
     * Body: address_id? (for tax; default: the customer's default address).
     * Creates a pending order to pay as usual.
     */
    public function accept(Request $request, SalesQuotationRequest $quotationRequest): JsonResponse
    {
        $addressId = $request->validate(['address_id' => ['sometimes', 'nullable', 'integer']])['address_id'] ?? null;
        $order = $this->quotations->acceptQuotation($this->quotation($request, $quotationRequest), 'online', null, $addressId === null ? null : (int) $addressId);

        return APIResponse::created($this->orders->order($order->load('items'), true, false), 'Quotation accepted: pay the order to complete it');
    }

    public function reject(Request $request, SalesQuotationRequest $quotationRequest): JsonResponse
    {
        $this->quotations->rejectQuotation($this->quotation($request, $quotationRequest));

        return APIResponse::success($this->presenter->request($this->quotations->getRequest($quotationRequest->refresh()), false), 'Quotation rejected');
    }

    private function quotation(Request $request, SalesQuotationRequest $quotationRequest): SalesQuotation
    {
        $quotation = $this->own($request, $quotationRequest)->quotation;

        return $quotation !== null && $quotation->status !== SalesQuotation::DRAFT
            ? $quotation : throw ApiException::unprocessable('quotation_not_sent', 'This request has no quotation yet.');
    }

    private function own(Request $request, SalesQuotationRequest $quotationRequest): SalesQuotationRequest
    {
        return $quotationRequest->customer_id === $this->customer($request)->id ? $quotationRequest : throw new NotFoundHttpException;
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
