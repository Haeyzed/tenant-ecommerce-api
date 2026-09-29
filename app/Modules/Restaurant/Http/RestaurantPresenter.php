<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Pos\Http\PosPresenter;
use App\Modules\Restaurant\Models\ModifierGroup;
use App\Modules\Restaurant\Models\ModifierOption;
use App\Modules\Restaurant\Models\RestaurantFloor;
use App\Modules\Restaurant\Models\RestaurantReservation;
use App\Modules\Restaurant\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant responses (spec §65.5). A table order is the POS sale shape
 * plus each line's kitchen status and menu options.
 */
final readonly class RestaurantPresenter
{
    public function __construct(private PosPresenter $pos) {}

    /**
     * @return array<string, mixed>
     */
    public function floor(RestaurantFloor $floor): array
    {
        return [
            'id' => $floor->id,
            'name' => $floor->name,
            'warehouse' => ['id' => $floor->warehouse_id, 'name' => $floor->relationLoaded('warehouse') ? $floor->warehouse?->name : null],
            'sort_order' => $floor->sort_order,
            'tables_count' => $floor->tables_count ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function table(RestaurantTable $table): array
    {
        /** @var Order|null $current */
        $current = $table->relationLoaded('currentOrder') ? $table->getRelation('currentOrder') : null;

        return [
            'id' => $table->id,
            'name' => $table->name,
            'seats' => $table->seats,
            'status' => $table->status,
            'floor' => ['id' => $table->restaurant_floor_id, 'name' => $table->relationLoaded('floor') ? $table->floor?->name : null],
            ...($table->relationLoaded('currentOrder') ? ['current_order' => $current === null ? null : [
                'id' => $current->id, 'order_number' => $current->order_number, 'total' => (string) $current->total, 'opened_at' => $current->placed_at->toIso8601String(),
            ]] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reservation(RestaurantReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'table' => $reservation->relationLoaded('table') ? $this->table($reservation->table) : ['id' => $reservation->restaurant_table_id],
            'customer_id' => $reservation->customer_id,
            'customer_name' => $reservation->customer_name,
            'customer_phone' => $reservation->customer_phone,
            'party_size' => $reservation->party_size,
            'reservation_time' => $reservation->reservation_time->toIso8601String(),
            'status' => $reservation->status,
            'order_id' => $reservation->order_id,
            'notes' => $reservation->notes,
            'created_at' => $reservation->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function group(ModifierGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'selection_type' => $group->selection_type,
            'is_required' => $group->is_required,
            'options' => $group->relationLoaded('options') ? $group->options->map(fn (ModifierOption $o): array => $this->option($o))->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function option(ModifierOption $option): array
    {
        return [
            'id' => $option->id,
            'modifier_group_id' => $option->modifier_group_id,
            'name' => $option->name,
            'price_adjustment' => (string) $option->price_adjustment,
            'is_active' => $option->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tableOrder(Order $order): array
    {
        $order->loadMissing(['items.product', 'items.warehouse']);
        $sale = $this->pos->sale($order);
        $modifiers = DB::connection('tenant')->table('order_item_modifiers')->whereIn('order_item_id', $order->items->modelKeys() ?: [0])
            ->orderBy('id')->get(['order_item_id', 'modifier_option_id', 'name_snapshot', 'price_adjustment_snapshot'])->groupBy('order_item_id');
        $items = $order->items->keyBy('id');

        $sale['items'] = array_map(static function (array $line) use ($items, $modifiers): array {
            /** @var OrderItem|null $item */
            $item = $items->get($line['id']);

            return [
                ...$line,
                'kitchen_status' => $item?->kitchen_status,
                'kitchen_ready_at' => $item?->kitchen_ready_at?->toIso8601String(),
                'modifiers' => $modifiers->get($line['id'], collect())->map(static fn (object $m): array => [
                    'modifier_option_id' => $m->modifier_option_id === null ? null : (int) $m->modifier_option_id,
                    'name' => $m->name_snapshot,
                    'price_adjustment' => (string) $m->price_adjustment_snapshot,
                ])->values()->all(),
            ];
        }, $sale['items'] ?? []);

        return [...$sale, 'restaurant_table_id' => $order->restaurant_table_id];
    }
}
