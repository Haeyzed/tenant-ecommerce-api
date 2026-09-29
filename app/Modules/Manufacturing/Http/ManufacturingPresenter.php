<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http;

use App\Modules\Manufacturing\Models\BillOfMaterial;
use App\Modules\Manufacturing\Models\BillOfMaterialItem;
use App\Modules\Manufacturing\Models\WorkOrder;
use App\Modules\Manufacturing\Models\WorkOrderMaterial;
use App\Modules\Manufacturing\Services\ManufacturingCostService;

final readonly class ManufacturingPresenter
{
    public function __construct(private ManufacturingCostService $costs) {}

    /**
     * @return array<string, mixed>
     */
    public function bom(BillOfMaterial $bom, bool $withCost = false): array
    {
        $bom->loadMissing(['items.component', 'items.componentVariant', 'variant']);
        $payload = [
            'id' => $bom->id,
            'product_id' => $bom->product_id,
            'product_variant_id' => $bom->product_variant_id,
            'variant_sku' => $bom->variant?->sku,
            'name' => $bom->name,
            'is_default' => $bom->is_default,
            'yield_quantity' => (string) $bom->yield_quantity,
            'notes' => $bom->notes,
            'items' => $bom->items->map(static fn (BillOfMaterialItem $i): array => [
                'id' => $i->id,
                'component_product_id' => $i->component_product_id,
                'component_product_variant_id' => $i->component_product_variant_id,
                'name' => $i->component->name,
                'sku' => $i->componentVariant?->sku ?? $i->component->sku,
                'quantity_required' => (string) $i->quantity_required,
                'unit_cost_snapshot' => $i->unit_cost_snapshot === null ? null : (string) $i->unit_cost_snapshot,
                'current_unit_cost' => ManufacturingCostService::componentCost($i),
            ])->all(),
        ];

        if ($withCost) {
            // Null when a component has no cost price.
            $payload['calculated_unit_cost'] = $this->costs->calculateUnitCost($bom);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function workOrder(WorkOrder $order): array
    {
        $order->loadMissing(['bom.product', 'bom.variant', 'warehouse', 'materials.component', 'materials.componentVariant']);

        return [
            'id' => $order->id,
            'work_order_number' => $order->work_order_number,
            'status' => $order->status,
            'bill_of_material' => ['id' => $order->bill_of_material_id, 'name' => $order->bom->name],
            'product' => ['id' => $order->bom->product_id, 'name' => $order->bom->product->name, 'variant_sku' => $order->bom->variant?->sku],
            'warehouse' => ['id' => $order->warehouse_id, 'name' => $order->warehouse->name],
            'quantity_to_produce' => (string) $order->quantity_to_produce,
            'scheduled_start_date' => $order->scheduled_start_date?->toDateString(),
            'scheduled_end_date' => $order->scheduled_end_date?->toDateString(),
            'started_at' => $order->started_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'notes' => $order->notes,
            'materials' => $order->materials->map(static fn (WorkOrderMaterial $m): array => [
                'component_product_id' => $m->component_product_id,
                'component_product_variant_id' => $m->component_product_variant_id,
                'name' => $m->component->name,
                'sku' => $m->componentVariant?->sku ?? $m->component->sku,
                'quantity_required' => (string) $m->quantity_required,
                'quantity_consumed' => $m->quantity_consumed === null ? null : (string) $m->quantity_consumed,
            ])->all(),
        ];
    }
}
