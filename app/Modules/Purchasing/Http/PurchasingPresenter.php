<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnItem;
use App\Modules\Purchasing\Models\PurchaseReturnReason;
use App\Modules\Purchasing\Models\QuotationRequest;
use App\Modules\Purchasing\Models\QuotationRequestItem;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Modules\Purchasing\Models\SupplierQuotation;
use App\Modules\Purchasing\Models\SupplierQuotationItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierService;

final readonly class PurchasingPresenter
{
    public function __construct(private CustomFieldService $customFields) {}

    /**
     * @return array<string, mixed>
     */
    public function supplier(Supplier $s, bool $detail = false): array
    {
        $base = [
            'id' => $s->id,
            'name' => $s->name,
            'contact_name' => $s->contact_name,
            'email' => $s->email,
            'phone' => $s->phone,
            'payment_terms' => $s->payment_terms,
            'is_active' => $s->is_active,
        ];

        return ! $detail ? $base : [
            ...$base,
            'address_line' => $s->address_line,
            'country_id' => $s->country_id,
            'state_id' => $s->state_id,
            'city_id' => $s->city_id,
            'custom_fields' => $this->customFields->valuesFor($s, SupplierService::ENTITY, CustomFieldService::ADMIN),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierProduct(SupplierProduct $link): array
    {
        return [
            'id' => $link->id,
            'supplier_id' => $link->supplier_id,
            'product' => $link->relationLoaded('product') ? ['id' => $link->product->id, 'name' => $link->product->name, 'sku' => $link->product->sku] : ['id' => $link->product_id],
            'supplier' => $link->relationLoaded('supplier') ? ['id' => $link->supplier->id, 'name' => $link->supplier->name, 'is_active' => $link->supplier->is_active] : null,
            'supplier_sku' => $link->supplier_sku,
            'cost_price' => $link->cost_price === null ? null : (string) $link->cost_price,
            'lead_time_days' => $link->lead_time_days,
        ];
    }

    /**
     * @param  string|null  $balance  in the order currency, when known
     * @return array<string, mixed>
     */
    public function purchaseOrder(PurchaseOrder $o, bool $detail = false, ?string $balance = null): array
    {
        $base = [
            'id' => $o->id,
            'po_number' => $o->po_number,
            'status' => $o->status,
            'supplier' => $o->relationLoaded('supplier') ? ['id' => $o->supplier->id, 'name' => $o->supplier->name] : ['id' => $o->supplier_id],
            'warehouse' => $o->relationLoaded('warehouse') ? ['id' => $o->warehouse->id, 'name' => $o->warehouse->name] : ['id' => $o->warehouse_id],
            'currency_code' => $o->currency_code,
            'exchange_rate_used' => $o->exchange_rate_used === null ? null : (string) $o->exchange_rate_used,
            'total' => $o->total(),
            'order_date' => $o->order_date->toDateString(),
            'expected_date' => $o->expected_date?->toDateString(),
            'submitted_at' => $o->submitted_at?->toIso8601String(),
            'received_at' => $o->received_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
        ];

        if (! $detail) {
            return $base;
        }

        return [
            ...$base,
            'notes' => $o->notes,
            'balance' => $balance,
            'created_by' => $o->relationLoaded('creator') && $o->creator !== null ? ['id' => $o->creator->id, 'name' => $o->creator->name] : null,
            'items' => $o->items->map(static fn (PurchaseOrderItem $i): array => [
                'id' => $i->id,
                'product' => $i->relationLoaded('product') ? ['id' => $i->product->id, 'name' => $i->product->name, 'sku' => $i->product->sku] : ['id' => $i->product_id],
                'variant' => $i->product_variant_id === null ? null : ($i->relationLoaded('variant') ? ['id' => $i->variant->id, 'sku' => $i->variant->sku] : ['id' => $i->product_variant_id]),
                'quantity_ordered' => (string) $i->quantity_ordered,
                'quantity_received' => (string) $i->quantity_received,
                'unit_cost' => (string) $i->unit_cost,
            ])->values()->all(),
            'custom_fields' => $this->customFields->valuesFor($o, PurchaseOrderService::ENTITY, CustomFieldService::ADMIN),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(SupplierPayment $p): array
    {
        return [
            'id' => $p->id,
            'supplier_id' => $p->supplier_id,
            'purchase_order' => $p->purchase_order_id === null ? null : ['id' => $p->purchase_order_id, 'po_number' => $p->relationLoaded('purchaseOrder') ? $p->purchaseOrder?->po_number : null],
            'purchase_return_id' => $p->purchase_return_id,
            'amount_due' => $p->amount_due === null ? null : (string) $p->amount_due,
            'amount_received' => $p->amount_received === null ? null : (string) $p->amount_received,
            'amount_paid' => (string) $p->amount_paid,
            'change_given' => $p->change_given === null ? null : (string) $p->change_given,
            'payment_method' => $p->payment_method,
            'account' => $p->account_id === null ? null : ($p->relationLoaded('account') && $p->account !== null ? ['id' => $p->account->id, 'code' => $p->account->code, 'name' => $p->account->name] : ['id' => $p->account_id]),
            'currency_code' => $p->currency_code,
            'exchange_rate_used' => $p->exchange_rate_used === null ? null : (string) $p->exchange_rate_used,
            'paid_at' => $p->paid_at->toIso8601String(),
            'reference' => $p->reference,
            'notes' => $p->notes,
            'recorded_by' => $p->relationLoaded('recorder') && $p->recorder !== null ? ['id' => $p->recorder->id, 'name' => $p->recorder->name] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quotationRequest(QuotationRequest $r, bool $detail = false): array
    {
        $base = [
            'id' => $r->id,
            'request_number' => $r->number(),
            'status' => $r->status,
            'warehouse' => $r->relationLoaded('warehouse') ? ['id' => $r->warehouse->id, 'name' => $r->warehouse->name] : ['id' => $r->warehouse_id],
            'respond_by' => $r->respond_by?->toDateString(),
            'requested_at' => $r->requested_at->toIso8601String(),
            'items_count' => $r->items_count ?? null,
            'quotations_count' => $r->quotations_count ?? null,
        ];

        return ! $detail ? $base : [
            ...$base,
            'notes' => $r->notes,
            'items' => $r->items->map(static fn (QuotationRequestItem $i): array => [
                'id' => $i->id,
                'product' => ['id' => $i->product_id, 'name' => $i->product?->name, 'sku' => $i->product?->sku],
                'variant' => $i->product_variant_id === null ? null : ['id' => $i->product_variant_id, 'sku' => $i->variant?->sku],
                'quantity_requested' => (string) $i->quantity_requested,
            ])->values()->all(),
            'quotations' => $r->quotations->map(fn (SupplierQuotation $q): array => $this->supplierQuotation($q))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierQuotation(SupplierQuotation $q): array
    {
        return [
            'id' => $q->id,
            'quotation_request_id' => $q->quotation_request_id,
            'supplier' => $q->relationLoaded('supplier') ? ['id' => $q->supplier->id, 'name' => $q->supplier->name] : ['id' => $q->supplier_id],
            'status' => $q->status,
            'valid_until' => $q->valid_until?->toDateString(),
            'received_at' => $q->received_at?->toIso8601String(),
            'notes' => $q->notes,
            'purchase_order_id' => $q->purchase_order_id,
            'items' => $q->relationLoaded('items') ? $q->items->map(static fn (SupplierQuotationItem $i): array => [
                'quotation_request_item_id' => $i->quotation_request_item_id,
                'unit_price' => (string) $i->unit_price,
                'lead_time_days' => $i->lead_time_days,
            ])->values()->all() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseReturn(PurchaseReturn $r, bool $detail = false): array
    {
        $currency = $r->relationLoaded('purchaseOrder') ? $r->purchaseOrder->currency_code : null;

        $base = [
            'id' => $r->id,
            'return_number' => $r->number(),
            'status' => $r->status,
            'resolution' => $r->resolution,
            'purchase_order' => $r->relationLoaded('purchaseOrder') ? ['id' => $r->purchaseOrder->id, 'po_number' => $r->purchaseOrder->po_number] : ['id' => $r->purchase_order_id],
            'supplier' => $r->relationLoaded('supplier') ? ['id' => $r->supplier->id, 'name' => $r->supplier->name] : ['id' => $r->supplier_id],
            'reason' => $r->relationLoaded('reason') ? ['id' => $r->reason->id, 'label' => $r->reason->label] : ['id' => $r->purchase_return_reason_id],
            'currency_code' => $currency,
            'value' => $currency === null ? null : $r->value($currency),
            'requested_at' => $r->requested_at->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
        ];

        return ! $detail ? $base : [
            ...$base,
            'warehouse' => $r->relationLoaded('warehouse') ? ['id' => $r->warehouse->id, 'name' => $r->warehouse->name] : ['id' => $r->warehouse_id],
            'note' => $r->note,
            'rejection_reason' => $r->rejection_reason,
            'items' => $r->items->map(static fn (PurchaseReturnItem $i): array => [
                'id' => $i->id,
                'purchase_order_item_id' => $i->purchase_order_item_id,
                'product' => $i->relationLoaded('orderItem') && $i->orderItem->relationLoaded('product') ? ['id' => $i->orderItem->product->id, 'name' => $i->orderItem->product->name] : null,
                'quantity' => (string) $i->quantity,
                'unit_cost' => (string) $i->unit_cost,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reason(PurchaseReturnReason $r): array
    {
        return ['id' => $r->id, 'label' => $r->label, 'is_active' => $r->is_active];
    }
}
