<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Restaurant\Models\RestaurantFloor;
use App\Modules\Restaurant\Models\RestaurantReservation;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Tables (spec §65.1, §65.5). occupied follows the table order; staff set
 * cleaning and available by hand, never while an order is open.
 */
final readonly class RestaurantTableService
{
    /** Statuses staff may set by hand (§65.1). */
    public const array MANUAL_STATUSES = [RestaurantTable::AVAILABLE, RestaurantTable::CLEANING];

    public function __construct(private RestaurantTableOrderService $tableOrders) {}

    /**
     * @param  array<string, mixed>  $data  name, seats
     */
    public function createTable(RestaurantFloor $floor, array $data): RestaurantTable
    {
        $validated = $this->validate($data, $floor->id, null, true);
        $table = new RestaurantTable;
        $table->forceFill([...$validated, 'restaurant_floor_id' => $floor->id, 'status' => RestaurantTable::AVAILABLE])->save();

        return $table->load('floor');
    }

    /**
     * @param  array<string, mixed>  $data  name?, seats?, restaurant_floor_id?
     */
    public function updateTable(RestaurantTable $table, array $data): RestaurantTable
    {
        $floorId = (int) ($data['restaurant_floor_id'] ?? $table->restaurant_floor_id);
        $validated = $this->validate($data, $floorId, $table->id, false);

        if ($floorId !== $table->restaurant_floor_id && $this->tableOrders->getCurrentOrder($table) !== null) {
            throw ApiException::unprocessable('table_has_open_order', 'Settle or void this table\'s order first.');
        }

        $table->forceFill($validated)->save();

        return $table->load('floor');
    }

    public function updateStatus(RestaurantTable $table, string $status): RestaurantTable
    {
        if (! in_array($status, self::MANUAL_STATUSES, true)) {
            throw ApiException::unprocessable('table_status_invalid', 'A table can be set to available or cleaning by hand.');
        }

        return DB::connection('tenant')->transaction(function () use ($table, $status): RestaurantTable {
            /** @var RestaurantTable $locked */
            $locked = RestaurantTable::query()->lockForUpdate()->findOrFail($table->id);

            if ($this->tableOrders->getCurrentOrder($locked) !== null) {
                throw ApiException::unprocessable('table_has_open_order', 'This table has an open order: settle or void it first.');
            }

            $locked->forceFill(['status' => $status])->save();

            return $locked->load('floor');
        });
    }

    /**
     * Blocked while an order is open or a reservation is upcoming; past
     * reservations go with the table.
     */
    public function deleteTable(RestaurantTable $table): void
    {
        if ($this->tableOrders->getCurrentOrder($table) !== null) {
            throw ApiException::unprocessable('table_has_open_order', 'Settle or void this table\'s order first.');
        }

        if (RestaurantReservation::query()->where('restaurant_table_id', $table->id)->whereIn('status', [RestaurantReservation::CONFIRMED, RestaurantReservation::SEATED])->exists()) {
            throw ApiException::unprocessable('table_has_reservations', 'This table has upcoming reservations: move or cancel them first.');
        }

        $table->delete();
    }

    /**
     * @param  array{floor_id?: int, status?: string}  $filters
     * @return Collection<int, RestaurantTable>
     */
    public function listTables(array $filters): Collection
    {
        $tables = RestaurantTable::query()->with('floor:id,name,warehouse_id')
            ->when(isset($filters['floor_id']), static fn ($q) => $q->where('restaurant_floor_id', $filters['floor_id']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('restaurant_floor_id')->orderBy('name')->get();

        // The open order of each table, in one query.
        $open = Order::query()->whereIn('restaurant_table_id', $tables->modelKeys() ?: [0])->where('status', Order::PENDING)->whereNull('cancelled_at')
            ->get(['id', 'order_number', 'restaurant_table_id', 'total', 'placed_at'])->keyBy('restaurant_table_id');

        return $tables->each(static fn (RestaurantTable $t) => $t->setRelation('currentOrder', $open->get($t->id)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, int $floorId, ?int $ignoreId, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return Validator::make($data, [
            'name' => [$required, 'string', 'max:40', Rule::unique('tenant.restaurant_tables', 'name')->where('restaurant_floor_id', $floorId)->ignore($ignoreId)],
            'seats' => [$required, 'integer', 'min:1', 'max:100'],
            'restaurant_floor_id' => ['sometimes', 'integer', Rule::exists('tenant.restaurant_floors', 'id')],
        ])->validate();
    }
}
