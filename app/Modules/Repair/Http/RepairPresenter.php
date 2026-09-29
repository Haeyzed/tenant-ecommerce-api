<?php

declare(strict_types=1);

namespace App\Modules\Repair\Http;

use App\Modules\Repair\Models\RepairJob;
use App\Modules\Repair\Models\RepairJobLabor;
use App\Modules\Repair\Models\RepairJobPart;
use App\Modules\Repair\Services\RepairJobService;

/**
 * Repair responses (spec §67.4). Part costs are for staff only.
 */
final readonly class RepairPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function job(RepairJob $job, bool $admin): array
    {
        $order = $job->relationLoaded('order') ? $job->order : null;

        return [
            'id' => $job->id,
            'job_number' => RepairJobService::number($job),
            'item_description' => $job->item_description,
            'status' => $job->status,
            'customer' => $job->customer_id === null ? null : ['id' => $job->customer_id, 'name' => $job->relationLoaded('customer') ? $job->customer?->name : null],
            'customer_name' => $job->customer_name,
            'customer_phone' => $job->customer_phone,
            'booking_id' => $job->booking_id,
            'warehouse' => ['id' => $job->warehouse_id, 'name' => $job->relationLoaded('warehouse') ? $job->warehouse?->name : null],
            'diagnosis_notes' => $job->diagnosis_notes,
            'estimated_cost' => $job->estimated_cost === null ? null : (string) $job->estimated_cost,
            'customer_approved_at' => $job->customer_approved_at?->toIso8601String(),
            'order' => $job->order_id === null ? null : ($order === null ? ['id' => $job->order_id] : [
                'id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status, 'payment_status' => $order->payment_status,
                'total' => (string) $order->total, 'currency_code' => $order->currency_code,
            ]),
            'parts' => $job->relationLoaded('parts') ? $job->parts->map(fn (RepairJobPart $p): array => $this->part($p, $admin))->values()->all() : [],
            'labor' => $job->relationLoaded('labor') ? $job->labor->map(fn (RepairJobLabor $l): array => $this->labor($l))->values()->all() : [],
            'received_at' => $job->received_at->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'picked_up_at' => $job->picked_up_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function part(RepairJobPart $part, bool $admin): array
    {
        return [
            'id' => $part->id,
            'product' => ['id' => $part->product_id, 'name' => $part->relationLoaded('product') ? $part->product?->name : null],
            'variant' => $part->product_variant_id === null ? null : ['id' => $part->product_variant_id, 'sku' => $part->relationLoaded('variant') ? $part->variant?->sku : null],
            'quantity' => (string) $part->quantity,
            ...($admin ? ['unit_cost_snapshot' => $part->unit_cost_snapshot === null ? null : (string) $part->unit_cost_snapshot] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function labor(RepairJobLabor $labor): array
    {
        return ['id' => $labor->id, 'description' => $labor->description, 'amount' => (string) $labor->amount];
    }
}
