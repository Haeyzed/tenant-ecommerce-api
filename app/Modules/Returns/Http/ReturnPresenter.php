<?php

declare(strict_types=1);

namespace App\Modules\Returns\Http;

use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Returns\Models\OrderReturnItem;
use App\Modules\Returns\Models\ReturnReason;

/**
 * JSON shapes of returns. The customer view hides the internal note.
 */
final class ReturnPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function return(OrderReturn $return, bool $admin): array
    {
        return [
            'id' => $return->id,
            'return_number' => $return->return_number,
            'order' => ['id' => $return->order_id, 'order_number' => $return->order?->order_number],
            'reason' => ['id' => $return->return_reason_id, 'label' => $return->reason?->label],
            'resolution_type' => $return->resolution_type,
            'status' => $return->status,
            'requires_physical_return' => $return->requires_physical_return,
            'customer_note' => $return->customer_note,
            'rejection_reason' => $return->rejection_reason,
            'refund_amount' => $return->refund_amount === null ? null : (string) $return->refund_amount,
            'requested_at' => $return->requested_at->toIso8601String(),
            'resolved_at' => $return->resolved_at?->toIso8601String(),
            'items' => $return->relationLoaded('items') ? $return->items->map(static fn (OrderReturnItem $i): array => [
                'id' => $i->id,
                'order_item_id' => $i->order_item_id,
                'name' => $i->orderItem?->name_snapshot,
                'quantity' => (string) $i->quantity,
                'condition' => $i->condition,
                'restocked' => $i->restocked,
                'exchange_for_product_id' => $i->exchange_for_product_id,
                'exchange_for_product_variant_id' => $i->exchange_for_product_variant_id,
            ])->values()->all() : [],
            'photos_count' => $return->getMedia('photos')->count(),
            ...($admin ? ['admin_note' => $return->admin_note, 'customer_id' => $return->customer_id] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reason(ReturnReason $reason): array
    {
        return ['id' => $reason->id, 'label' => $reason->label, 'requires_photo' => (bool) $reason->requires_photo, 'is_active' => (bool) $reason->is_active];
    }
}
