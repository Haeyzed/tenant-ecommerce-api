<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http;

use App\Modules\Shipping\Models\DeliveryAssignment;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentItem;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Models\ShippingZoneRegion;

/**
 * JSON shapes of shipping records.
 */
final class ShippingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function zone(ShippingZone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'is_active' => (bool) ($zone->is_active ?? true),
            'regions' => $zone->regions->map(static fn (ShippingZoneRegion $r): array => ['country_id' => $r->country_id, 'state_id' => $r->state_id])->values()->all(),
            'methods_count' => $zone->relationLoaded('methods') ? $zone->methods->count() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function method(ShippingMethod $method, bool $public): array
    {
        return [
            'id' => $method->id,
            'name' => $method->name,
            'fulfillment_type' => $method->fulfillment_type,
            'courier_provider' => $method->courier_provider,
            'cost' => (string) $method->cost,
            'estimated_days' => $method->estimated_days,
            ...($public ? [] : [
                'shipping_zone' => ['id' => $method->shipping_zone_id, 'name' => $method->zone?->name],
                'is_active' => (bool) ($method->is_active ?? true),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shipment(Shipment $shipment, bool $public = false): array
    {
        $assignment = $shipment->relationLoaded('assignment') ? $shipment->assignment : null;

        return [
            'id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'order_number' => $shipment->relationLoaded('order') ? $shipment->order?->order_number : null,
            'fulfillment_type' => $shipment->fulfillment_type,
            'status' => $shipment->status,
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->tracking_url,
            'dispatched_at' => $shipment->dispatched_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            ...($public ? [] : ['warehouse' => ['id' => $shipment->warehouse_id, 'name' => $shipment->relationLoaded('warehouse') ? $shipment->warehouse?->name : null]]),
            'items' => $shipment->relationLoaded('items') ? $shipment->items->map(static fn (ShipmentItem $i): array => [
                'order_item_id' => $i->order_item_id,
                'name' => $i->relationLoaded('orderItem') ? $i->orderItem?->name_snapshot : null,
                'quantity' => (string) $i->quantity,
            ])->values()->all() : [],
            'delivery' => $assignment === null ? null : $this->assignment($assignment, $public),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignment(DeliveryAssignment $assignment, bool $public = false): array
    {
        return [
            'id' => $assignment->id,
            'status' => $assignment->status,
            'driver' => $assignment->relationLoaded('driver') && $assignment->driver !== null
                ? ['name' => $assignment->driver->name, ...($public ? [] : ['id' => $assignment->driver_id, 'phone' => $assignment->driver->phone])]
                : null,
            'assigned_at' => $assignment->assigned_at->toIso8601String(),
            'picked_up_at' => $assignment->picked_up_at?->toIso8601String(),
            'delivered_at' => $assignment->delivered_at?->toIso8601String(),
            'delivery_notes' => $public ? null : $assignment->delivery_notes,
        ];
    }

    /**
     * A driver's delivery: where to take what.
     *
     * @return array<string, mixed>
     */
    public function delivery(DeliveryAssignment $assignment): array
    {
        $shipment = $assignment->shipment;
        $order = $shipment->order;

        return [
            'id' => $assignment->id,
            'status' => $assignment->status,
            'assigned_at' => $assignment->assigned_at->toIso8601String(),
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'warehouse' => ['id' => $shipment->warehouse_id, 'name' => $shipment->warehouse?->name],
            'items' => $shipment->items->map(static fn (ShipmentItem $i): array => ['name' => $i->orderItem?->name_snapshot, 'quantity' => (string) $i->quantity])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function driver(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'vehicle_type' => $driver->vehicle_type,
            'status' => $driver->status,
            'is_available' => (bool) $driver->is_available,
            'user_id' => $driver->user_id,
            'phone_verified' => $driver->phone_verified_at !== null,
            'has_pin' => $driver->pin_hash !== null,
            'last_login_at' => $driver->last_login_at?->toIso8601String(),
        ];
    }
}
