<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentItem;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shipments (spec §36.2, §36.3). A shipment moves goods, not stock: stock
 * was deducted at confirmation. Allocation is checked under the order lock;
 * after every change the order status is derived (§39.4).
 */
final readonly class ShipmentService
{
    public function __construct(
        private OrderService $orders,
        private WarehouseService $warehouses,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  list<array{order_item_id: int, quantity: string|int|float}>  $items
     * @param  array<string, mixed>  $data  shipping_method_id, carrier, tracking_number, tracking_url
     */
    public function createShipment(Order $order, Warehouse $warehouse, array $items, array $data = [], ?User $by = null): Shipment
    {
        return DB::connection('tenant')->transaction(function () use ($order, $warehouse, $items, $data, $by): Shipment {
            $locked = $this->lockShippable($order);
            $lines = $locked->items->keyBy('id');
            $allocated = $this->allocated($locked);
            $seen = [];

            if ($items === []) {
                throw ValidationException::withMessages(['items' => ['Choose the lines to ship.']]);
            }

            foreach ($items as $index => $row) {
                /** @var OrderItem|null $line */
                $line = $lines->get((int) ($row['order_item_id'] ?? 0));
                $quantity = is_numeric($row['quantity'] ?? null) ? Quantity::normalize((string) $row['quantity']) : '0';

                if ($line === null || ! $line->isPhysical() || isset($seen[$line->id])) {
                    throw ValidationException::withMessages(["items.{$index}.order_item_id" => ['Choose a physical line of this order, once.']]);
                }

                if ($line->warehouse_id !== $warehouse->id) {
                    throw ValidationException::withMessages(["items.{$index}.order_item_id" => ['This line ships from another warehouse.']]);
                }

                $free = Quantity::sub((string) $line->quantity, $allocated[$line->id] ?? '0');

                if (! Quantity::isPositive($quantity) || Quantity::cmp($quantity, $free) > 0) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => ["At most {$free} of this line is left to ship."]]);
                }

                $seen[$line->id] = $quantity;
            }

            return $this->create($locked, $warehouse, $seen, $data, $by);
        });
    }

    /**
     * The one-click default: one shipment per warehouse for every
     * unallocated quantity.
     *
     * @return Collection<int, Shipment>
     */
    public function createShipmentsForOrder(Order $order, ?User $by = null): Collection
    {
        return DB::connection('tenant')->transaction(function () use ($order, $by): Collection {
            $locked = $this->lockShippable($order);
            $allocated = $this->allocated($locked);
            $byWarehouse = [];

            foreach ($locked->items as $line) {
                $free = Quantity::sub((string) $line->quantity, $allocated[$line->id] ?? '0');

                if ($line->isPhysical() && $line->warehouse_id !== null && Quantity::isPositive($free)) {
                    $byWarehouse[$line->warehouse_id][$line->id] = $free;
                }
            }

            if ($byWarehouse === []) {
                throw ApiException::unprocessable('nothing_to_ship', 'Every line of this order is already in a shipment.');
            }

            ksort($byWarehouse);

            return collect($byWarehouse)->map(fn (array $lines, int $warehouseId): Shipment => $this->create($locked, Warehouse::query()->findOrFail($warehouseId), $lines, [], $by))->values();
        });
    }

    /**
     * Courier tracking data, before delivery.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTracking(Shipment $shipment, array $data): Shipment
    {
        $validated = validator($data, [
            'carrier' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'tracking_url' => ['sometimes', 'nullable', 'url:https,http', 'max:255'],
        ])->validate();

        if ($shipment->fulfillment_type !== ShippingMethod::COURIER) {
            throw ApiException::unprocessable('shipment_not_courier', 'Only courier shipments carry tracking data.');
        }

        if (in_array($shipment->status, [Shipment::DELIVERED, Shipment::CANCELLED], true)) {
            throw ApiException::unprocessable('shipment_closed', 'This shipment is closed.');
        }

        $shipment->forceFill($validated)->save();

        return $shipment;
    }

    public function markDispatched(Shipment $shipment): Shipment
    {
        $this->transition($shipment, [Shipment::PENDING], Shipment::DISPATCHED, function (Shipment $locked): void {
            if ($locked->fulfillment_type === ShippingMethod::COURIER && blank($locked->tracking_number)) {
                throw ApiException::unprocessable('tracking_required', 'Add the tracking number before dispatching a courier shipment.');
            }

            $locked->dispatched_at = now();
            $this->adjustShipped($locked, true);
        });

        $shipment->loadMissing('order', 'items.orderItem');
        $this->notifications->dispatch('order.shipped', $shipment->order, [
            'customer_name' => (string) ($shipment->order->customer_name ?? ''),
            'order_number' => $shipment->order->order_number,
            'carrier' => (string) ($shipment->carrier ?? $shipment->shippingMethod?->name ?? 'our delivery team'),
            'tracking_number' => (string) ($shipment->tracking_number ?? '-'),
        ]);

        return $shipment;
    }

    public function markInTransit(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, [Shipment::DISPATCHED], Shipment::IN_TRANSIT);
    }

    public function markDelivered(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, [Shipment::DISPATCHED, Shipment::IN_TRANSIT], Shipment::DELIVERED, static function (Shipment $locked): void {
            $locked->delivered_at = now();
        });
    }

    public function markFailed(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, [Shipment::PENDING, Shipment::DISPATCHED, Shipment::IN_TRANSIT], Shipment::FAILED, function (Shipment $locked): void {
            if ($locked->isOnTheWay()) {
                $this->adjustShipped($locked, false);
            }
        });
    }

    /**
     * A failed in-house shipment returns to pending when reassigned.
     */
    public function reopen(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, [Shipment::FAILED], Shipment::PENDING, static function (Shipment $locked): void {
            $locked->dispatched_at = null;
        });
    }

    /**
     * Allowed only before delivery; shipped quantity is given back.
     */
    public function cancelShipment(Shipment $shipment): Shipment
    {
        return $this->transition($shipment, [Shipment::PENDING, Shipment::DISPATCHED, Shipment::IN_TRANSIT, Shipment::FAILED], Shipment::CANCELLED, function (Shipment $locked): void {
            if ($locked->isOnTheWay()) {
                $this->adjustShipped($locked, false);
            }
        });
    }

    /**
     * @param  array{status?: string, warehouse_id?: int, driver_id?: int, order_id?: int, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Shipment>
     */
    public function listShipments(array $filters, ?User $viewer = null): LengthAwarePaginator
    {
        $visible = $this->warehouses->visibleIds($viewer);

        return Shipment::query()->with(['order:id,order_number', 'warehouse:id,name', 'assignment.driver:id,name'])->withCount('items')
            ->when($visible !== null, static fn (Builder $q) => $q->whereIn('warehouse_id', $visible))
            ->when($filters['status'] ?? null, static fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['warehouse_id'] ?? null, static fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['order_id'] ?? null, static fn (Builder $q, $v) => $q->where('order_id', $v))
            ->when($filters['driver_id'] ?? null, static fn (Builder $q, $v) => $q->whereHas('assignment', static fn (Builder $a) => $a->where('driver_id', $v)))
            ->when($filters['from'] ?? null, static fn (Builder $q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, static fn (Builder $q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return Collection<int, Shipment>
     */
    public function forOrder(Order $order): Collection
    {
        return Shipment::query()->with(['items.orderItem:id,name_snapshot,sku_snapshot', 'warehouse:id,name', 'assignment.driver:id,name,phone', 'shippingMethod:id,name'])
            ->where('order_id', $order->id)->orderBy('id')->get();
    }

    /**
     * @param  array<int, string>  $lines  order item id => quantity
     * @param  array<string, mixed>  $data
     */
    private function create(Order $order, Warehouse $warehouse, array $lines, array $data, ?User $by): Shipment
    {
        $methodId = $data['shipping_method_id'] ?? $order->shipping_method_id;
        $method = $methodId === null ? null : ShippingMethod::withTrashed()->find((int) $methodId);

        if ($methodId !== null && $method === null) {
            throw ValidationException::withMessages(['shipping_method_id' => ['The shipping method does not exist.']]);
        }

        $type = $method?->fulfillment_type ?? ShippingMethod::COURIER;

        $shipment = new Shipment;
        $shipment->forceFill([
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'shipping_method_id' => $method?->id,
            'fulfillment_type' => $type,
            'carrier' => $type === ShippingMethod::COURIER ? ($data['carrier'] ?? $method?->courier_provider) : null,
            'tracking_number' => $type === ShippingMethod::COURIER ? ($data['tracking_number'] ?? null) : null,
            'tracking_url' => $type === ShippingMethod::COURIER ? ($data['tracking_url'] ?? null) : null,
            'status' => Shipment::PENDING,
            'created_by_user_id' => $by?->id,
        ])->save();

        foreach ($lines as $orderItemId => $quantity) {
            $item = new ShipmentItem;
            $item->forceFill(['shipment_id' => $shipment->id, 'order_item_id' => $orderItemId, 'quantity' => $quantity])->save();
        }

        return $shipment->load('items');
    }

    /**
     * @param  list<string>  $from
     * @param  (callable(Shipment): void)|null  $apply
     */
    private function transition(Shipment $shipment, array $from, string $to, ?callable $apply = null): Shipment
    {
        $becameDelivered = false;

        DB::connection('tenant')->transaction(function () use ($shipment, $from, $to, $apply, &$becameDelivered): void {
            $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            /** @var Shipment $locked */
            $locked = Shipment::query()->with('items')->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw ApiException::invalidTransition($locked->status, $to);
            }

            if ($apply !== null) {
                $apply($locked);
            }

            $locked->status = $to;
            $locked->save();
            $shipment->setRawAttributes($locked->getAttributes(), true);

            $becameDelivered = $this->deriveOrderStatus($order);
        });

        if ($becameDelivered) {
            $this->orders->notifyStatus($shipment->order()->firstOrFail(), Order::DELIVERED);
        }

        return $shipment;
    }

    /**
     * §39.4: partially_shipped / shipped from dispatched quantities;
     * delivered once every physical line is fully shipped and every
     * non-cancelled shipment is delivered. The caller holds the order lock.
     *
     * @return bool whether the order became delivered
     */
    private function deriveOrderStatus(Order $order): bool
    {
        if (! in_array($order->status, [Order::PENDING, Order::PROCESSING, Order::PARTIALLY_SHIPPED, Order::SHIPPED], true)) {
            return false;
        }

        $order->load('items.product');
        $physical = $order->items->filter(static fn (OrderItem $i): bool => $i->isPhysical());
        $anyShipped = $physical->contains(static fn (OrderItem $i): bool => Quantity::isPositive((string) $i->quantity_shipped));
        $allShipped = $physical->isNotEmpty() && $physical->every(static fn (OrderItem $i): bool => Quantity::cmp((string) $i->quantity_shipped, (string) $i->quantity) >= 0);

        $open = Shipment::query()->where('order_id', $order->id)->where('status', '!=', Shipment::CANCELLED)->pluck('status');
        $allDelivered = $open->isNotEmpty() && $open->every(static fn (string $s): bool => $s === Shipment::DELIVERED);

        $status = match (true) {
            $allShipped && $allDelivered => Order::DELIVERED,
            $allShipped => Order::SHIPPED,
            $anyShipped => Order::PARTIALLY_SHIPPED,
            in_array($order->status, [Order::PARTIALLY_SHIPPED, Order::SHIPPED], true) => Order::PROCESSING,
            default => $order->status,
        };

        if ($status !== $order->status) {
            $this->orders->setStatus($order, $status);
        }

        return $status === Order::DELIVERED;
    }

    private function adjustShipped(Shipment $shipment, bool $add): void
    {
        foreach ($shipment->items as $item) {
            $line = OrderItem::query()->whereKey($item->order_item_id)->lockForUpdate()->firstOrFail();
            $shipped = $add ? Quantity::add((string) $line->quantity_shipped, (string) $item->quantity)
                : Quantity::max('0', Quantity::sub((string) $line->quantity_shipped, (string) $item->quantity));
            $line->forceFill(['quantity_shipped' => $shipped])->save();
        }
    }

    private function lockShippable(Order $order): Order
    {
        /** @var Order $locked */
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if ($locked->confirmed_at === null || in_array($locked->status, [Order::CANCELLED, Order::REFUNDED, Order::COMPLETED], true)) {
            throw ApiException::unprocessable('order_not_shippable', 'Only a confirmed, open order can ship.');
        }

        return $locked->load('items.product');
    }

    /**
     * Quantity per order line in non-cancelled shipments.
     *
     * @return array<int, string>
     */
    private function allocated(Order $order): array
    {
        return ShipmentItem::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->where('shipments.order_id', $order->id)
            ->where('shipments.status', '!=', Shipment::CANCELLED)
            ->groupBy('shipment_items.order_item_id')
            ->selectRaw('shipment_items.order_item_id, SUM(shipment_items.quantity) as allocated')
            ->pluck('allocated', 'order_item_id')
            ->map(static fn ($v): string => Quantity::normalize((string) $v))
            ->all();
    }
}
