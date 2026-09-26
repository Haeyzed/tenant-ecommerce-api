<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockAdjustmentItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseProductPrice;

/**
 * JSON shapes of inventory records (admin only: warehouses are never shown
 * to customers, §32.1).
 */
final class InventoryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function warehouse(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'address_line' => $warehouse->address_line,
            'city_id' => $warehouse->city_id,
            'state_id' => $warehouse->state_id,
            'country_id' => $warehouse->country_id,
            'phone' => $warehouse->phone,
            'is_active' => (bool) $warehouse->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stockRow(Inventory $row): array
    {
        return [
            'id' => $row->id,
            'product' => ['id' => $row->product_id, 'name' => $row->product->name, 'sku' => $row->product->sku],
            'variant' => $row->variant === null ? null : ['id' => $row->variant->id, 'sku' => $row->variant->sku],
            'quantity' => (string) $row->quantity,
            'reserved_quantity' => (string) $row->reserved_quantity,
            'available' => $row->available(),
        ];
    }

    /**
     * A row of the stock-level queries (low stock, out of stock, levels).
     *
     * @return array<string, mixed>
     */
    public function stockLevel(object $row): array
    {
        return [
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'product_variant_id' => $row->product_variant_id !== null ? (int) $row->product_variant_id : null,
            'sku' => $row->sku,
            'quantity' => bcadd((string) $row->quantity, '0', 3),
            'reserved_quantity' => bcadd((string) $row->reserved_quantity, '0', 3),
            'available' => bcadd((string) $row->available, '0', 3),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function movement(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'movement_type' => $movement->movement_type,
            'warehouse' => ['id' => $movement->warehouse_id, 'name' => $movement->warehouse?->name],
            'product' => ['id' => $movement->product_id, 'name' => $movement->product?->name],
            'variant' => $movement->product_variant_id === null ? null : ['id' => $movement->product_variant_id, 'sku' => $movement->variant?->sku],
            'quantity_delta' => (string) $movement->quantity_delta,
            'reserved_delta' => (string) $movement->reserved_delta,
            'quantity_after' => (string) $movement->quantity_after,
            'reserved_after' => (string) $movement->reserved_after,
            'unit_cost_snapshot' => $movement->unit_cost_snapshot !== null ? (string) $movement->unit_cost_snapshot : null,
            'reference' => $movement->reference_type === null ? null : ['type' => $movement->reference_type, 'id' => $movement->reference_id],
            'reason' => $movement->reason,
            'user' => $movement->user_id === null ? null : ['id' => $movement->user_id, 'name' => $movement->user?->name],
            'created_at' => $movement->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transfer(StockTransfer $transfer, bool $detail): array
    {
        $base = [
            'id' => $transfer->id,
            'status' => $transfer->status,
            'from_warehouse' => ['id' => $transfer->from_warehouse_id, 'name' => $transfer->fromWarehouse->name],
            'to_warehouse' => ['id' => $transfer->to_warehouse_id, 'name' => $transfer->toWarehouse->name],
            'notes' => $transfer->notes,
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
            'created_at' => $transfer->created_at?->toIso8601String(),
        ];

        if (! $detail) {
            return [...$base, 'items_count' => (int) $transfer->getAttribute('items_count')];
        }

        return [
            ...$base,
            'created_by' => ['id' => $transfer->created_by, 'name' => $transfer->creator?->name],
            'items' => $transfer->items->map(static fn (StockTransferItem $item): array => [
                'id' => $item->id,
                'product' => ['id' => $item->product_id, 'name' => $item->product->name, 'sku' => $item->product->sku],
                'variant' => $item->variant === null ? null : ['id' => $item->variant->id, 'sku' => $item->variant->sku],
                'quantity_sent' => (string) $item->quantity_sent,
                'quantity_received' => (string) $item->quantity_received,
                'remaining' => $item->remaining(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adjustment(StockAdjustment $adjustment, bool $detail): array
    {
        $base = [
            'id' => $adjustment->id,
            'status' => $adjustment->status,
            'warehouse' => ['id' => $adjustment->warehouse_id, 'name' => $adjustment->warehouse->name],
            'notes' => $adjustment->notes,
            'created_by' => ['id' => $adjustment->created_by, 'name' => $adjustment->creator?->name],
            'submitted_at' => $adjustment->submitted_at?->toIso8601String(),
            'created_at' => $adjustment->created_at?->toIso8601String(),
        ];

        if (! $detail) {
            return [...$base, 'items_count' => (int) $adjustment->getAttribute('items_count')];
        }

        $attachment = $adjustment->getFirstMedia('attachment');

        return [
            ...$base,
            'attachment' => $attachment === null ? null : ['id' => $attachment->id, 'name' => $attachment->file_name, 'size' => $attachment->size],
            'items' => $adjustment->items->map(fn (StockAdjustmentItem $item): array => $this->adjustmentItem($item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adjustmentItem(StockAdjustmentItem $item): array
    {
        return [
            'id' => $item->id,
            'product' => ['id' => $item->product_id, 'name' => $item->product->name, 'sku' => $item->product->sku],
            'variant' => $item->variant === null ? null : ['id' => $item->variant->id, 'sku' => $item->variant->sku],
            'action' => $item->action,
            'quantity' => (string) $item->quantity,
            'unit_cost_snapshot' => $item->unit_cost_snapshot !== null ? (string) $item->unit_cost_snapshot : null,
            'notes' => $item->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function price(WarehouseProductPrice $price): array
    {
        return [
            'id' => $price->id,
            'warehouse' => ['id' => $price->warehouse_id, 'name' => $price->warehouse->name],
            'variant' => $price->variant === null ? null : ['id' => $price->variant->id, 'sku' => $price->variant->sku],
            'price' => (string) $price->price,
            'compare_at_price' => $price->compare_at_price !== null ? (string) $price->compare_at_price : null,
        ];
    }
}
