<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Shared\Exceptions\ApiException;
use App\Shared\Metrics\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The kitchen view (spec §65.3): each dish moves pending → preparing →
 * ready → served on its own; reaching ready stamps kitchen_ready_at.
 */
final readonly class KitchenDisplayService
{
    public const array STATUSES = ['pending', 'preparing', 'ready', 'served'];

    /**
     * Open table orders with dishes still to serve, oldest first, grouped
     * by table.
     *
     * @param  array{floor_id?: int}  $filters
     * @return list<array<string, mixed>>
     */
    public function getActiveTickets(array $filters = []): array
    {
        $orders = Order::query()->whereNotNull('restaurant_table_id')->where('status', Order::PENDING)->whereNull('cancelled_at')
            ->whereHas('items', static fn ($q) => $q->whereIn('kitchen_status', ['pending', 'preparing', 'ready']))
            ->when(isset($filters['floor_id']), static fn ($q) => $q->whereIn('restaurant_table_id', RestaurantTable::query()->select('id')->where('restaurant_floor_id', $filters['floor_id'])))
            ->with(['items' => static fn ($q) => $q->whereNotNull('kitchen_status')->orderBy('id')])
            ->orderBy('placed_at')->orderBy('id')->get(['id', 'order_number', 'restaurant_table_id', 'placed_at']);

        $tables = RestaurantTable::query()->with('floor:id,name')->whereKey($orders->pluck('restaurant_table_id')->unique()->all())->get()->keyBy('id');
        $modifiers = DB::connection('tenant')->table('order_item_modifiers')->whereIn('order_item_id', $orders->flatMap(static fn (Order $o) => $o->items->modelKeys())->all() ?: [0])
            ->orderBy('id')->get(['order_item_id', 'name_snapshot'])->groupBy('order_item_id');

        return $orders->map(static function (Order $order) use ($tables, $modifiers): array {
            $table = $tables->get($order->restaurant_table_id);

            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'opened_at' => $order->placed_at->toIso8601String(),
                'table' => $table === null ? null : ['id' => $table->id, 'name' => $table->name, 'floor' => $table->floor?->name],
                'items' => $order->items->map(static fn (OrderItem $item): array => [
                    'id' => $item->id,
                    'name' => $item->name_snapshot,
                    'quantity' => (string) $item->quantity,
                    'modifiers' => $modifiers->get($item->id, collect())->pluck('name_snapshot')->values()->all(),
                    'kitchen_status' => $item->kitchen_status,
                    'sent_at' => $item->created_at->toIso8601String(),
                    'ready_at' => $item->kitchen_ready_at?->toIso8601String(),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Forward only; a skipped step still stamps kitchen_ready_at.
     */
    public function updateItemStatus(OrderItem $item, string $status): OrderItem
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw ApiException::unprocessable('kitchen_status_invalid', 'Use pending, preparing, ready or served.');
        }

        return DB::connection('tenant')->transaction(function () use ($item, $status): OrderItem {
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->lockForUpdate()->findOrFail($item->id);
            $order = Order::query()->findOrFail($locked->order_id);

            if ($locked->kitchen_status === null || $order->restaurant_table_id === null) {
                throw ApiException::unprocessable('not_a_kitchen_item', 'This line was not sent to the kitchen.');
            }

            if ($order->cancelled_at !== null) {
                throw ApiException::unprocessable('order_cancelled', 'This order was voided.');
            }

            $from = (int) array_search($locked->kitchen_status, self::STATUSES, true);
            $to = (int) array_search($status, self::STATUSES, true);

            if ($to <= $from) {
                throw ApiException::invalidTransition((string) $locked->kitchen_status, $status);
            }

            $locked->forceFill([
                'kitchen_status' => $status,
                'kitchen_ready_at' => $to >= 2 ? ($locked->kitchen_ready_at ?? now()) : null,
            ])->save();

            return $locked;
        });
    }

    /**
     * Average preparation minutes (kitchen_ready_at − sent), open tickets,
     * table orders completed in the range and dishes sent per local hour.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(DateRange $range): array
    {
        $db = DB::connection('tenant');
        $ready = $db->table('order_items')->whereNotNull('kitchen_ready_at')->whereBetween('kitchen_ready_at', [$range->startUtc(), $range->endUtc()])
            ->get(['created_at', 'kitchen_ready_at']);
        $minutes = $ready->map(static fn (object $r): float => max(0, CarbonImmutable::parse($r->kitchen_ready_at)->diffInSeconds(CarbonImmutable::parse($r->created_at), true)) / 60);

        $hours = array_fill(0, 24, 0);
        $sent = $db->table('order_items')->whereNotNull('kitchen_status')->whereBetween('created_at', [$range->startUtc(), $range->endUtc()])->pluck('created_at');

        foreach ($sent as $at) {
            $hours[(int) CarbonImmutable::parse($at, 'UTC')->setTimezone($range->zone())->format('G')]++;
        }

        arsort($hours);

        return [
            'range' => $range->toArray(),
            'average_preparation_minutes' => $minutes->isEmpty() ? null : round((float) $minutes->avg(), 1),
            'dishes_ready' => $ready->count(),
            'open_tickets' => $this->openTickets(),
            'tickets_completed' => $db->table('orders')->whereNotNull('restaurant_table_id')->where('status', Order::COMPLETED)
                ->whereBetween('completed_at', [$range->startUtc(), $range->endUtc()])->count(),
            'busiest_hours' => array_map(static fn (int $hour, int $count): array => ['hour' => $hour, 'dishes' => $count],
                array_slice(array_keys(array_filter($hours)), 0, 3), array_slice(array_values(array_filter($hours)), 0, 3)),
        ];
    }

    public function openTickets(): int
    {
        return Order::query()->whereNotNull('restaurant_table_id')->where('status', Order::PENDING)->whereNull('cancelled_at')->count();
    }
}
