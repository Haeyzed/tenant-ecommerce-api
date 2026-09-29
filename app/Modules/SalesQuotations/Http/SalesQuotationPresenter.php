<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Http;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\SalesQuotations\Models\SalesQuotation;
use App\Modules\SalesQuotations\Models\SalesQuotationItem;
use App\Modules\SalesQuotations\Models\SalesQuotationRequest;
use App\Modules\SalesQuotations\Models\SalesQuotationRequestItem;
use App\Modules\SalesQuotations\Services\SalesQuotationService;
use App\Shared\Support\Money;

/**
 * Requests with their quote. Customers see the quote only once it is sent;
 * staff see who created it, the agent and custom fields.
 */
final readonly class SalesQuotationPresenter
{
    public function __construct(private CustomFieldService $customFields) {}

    /**
     * @return array<string, mixed>
     */
    public function request(SalesQuotationRequest $request, bool $admin, bool $detail = true): array
    {
        $quotation = $request->relationLoaded('quotation') ? $request->quotation : null;
        $visible = $quotation !== null && ($admin || $quotation->status !== SalesQuotation::DRAFT);

        return [
            'id' => $request->id,
            'status' => $request->status,
            'currency_code' => $request->currency_code,
            'notes' => $request->notes,
            'requested_at' => $request->requested_at->toIso8601String(),
            'customer' => $request->relationLoaded('customer') && $request->customer !== null
                ? ['id' => $request->customer->id, 'name' => $request->customer->name, ...($admin ? ['email' => $request->customer->email] : [])] : null,
            ...($admin ? [
                'sales_agent' => $request->relationLoaded('salesAgent') && $request->salesAgent !== null
                    ? ['id' => $request->salesAgent->id, 'name' => $request->salesAgent->name, 'agent_code' => $request->salesAgent->agent_code] : null,
                'created_by_user_id' => $request->created_by_user_id,
            ] : []),
            'items_count' => $request->getAttributes()['items_count'] ?? ($request->relationLoaded('items') ? $request->items->count() : null),
            ...($detail && $request->relationLoaded('items') ? ['items' => $request->items->map(static fn (SalesQuotationRequestItem $i): array => [
                'id' => $i->id,
                'product' => $i->product === null ? null : ['id' => $i->product->id, 'name' => $i->product->name, 'sku' => $i->product->sku],
                'variant' => $i->variant === null ? null : ['id' => $i->variant->id, 'sku' => $i->variant->sku],
                'quantity_requested' => (string) $i->quantity_requested,
            ])->values()->all()] : []),
            'quotation' => $visible ? $this->quotation($quotation, $admin, $detail) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quotation(SalesQuotation $q, bool $admin, bool $detail = true): array
    {
        return [
            'id' => $q->id,
            'quotation_number' => $q->quotation_number,
            'status' => $q->status,
            'currency_code' => $q->currency_code,
            'subtotal' => (string) $q->subtotal,
            'discount_amount' => (string) $q->discount_amount,
            'total' => Money::normalize($q->total()),
            'valid_until' => $q->valid_until?->toDateString(),
            'notes' => $q->notes,
            'sent_at' => $q->sent_at?->toIso8601String(),
            'responded_at' => $q->responded_at?->toIso8601String(),
            'converted_order_id' => $q->converted_order_id,
            ...($detail && $q->relationLoaded('items') ? ['items' => $q->items->map(static fn (SalesQuotationItem $i): array => [
                'request_item_id' => $i->sales_quotation_request_item_id,
                'unit_price' => (string) $i->unit_price,
                'discount_amount' => $i->discount_amount === null ? null : (string) $i->discount_amount,
            ])->values()->all()] : []),
            ...($admin && $detail ? ['custom_fields' => $this->customFields->valuesFor($q, SalesQuotationService::ENTITY, CustomFieldService::ADMIN)] : []),
        ];
    }
}
