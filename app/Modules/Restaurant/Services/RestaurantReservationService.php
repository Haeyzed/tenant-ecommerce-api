<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Restaurant\Jobs\MarkTableReserved;
use App\Modules\Restaurant\Models\RestaurantReservation;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Reservations (spec §65.1). The table turns reserved 30 minutes ahead
 * (MarkTableReserved, A-55); seating opens the table order, and settling
 * it completes the reservation. Overlaps are not checked in v1.
 */
final readonly class RestaurantReservationService
{
    /** Minutes before the reservation the table is held (§65.1). */
    public const int HOLD_MINUTES = 30;

    public function __construct(private RestaurantTableOrderService $tableOrders) {}

    /**
     * @param  array<string, mixed>  $data  restaurant_table_id, customer_id?, customer_name?, customer_phone?, party_size, reservation_time, notes?
     */
    public function createReservation(array $data): RestaurantReservation
    {
        $this->tableOrders->assertTablesEnabled();
        $validated = $this->validate($data, true);
        $customer = isset($validated['customer_id']) ? Customer::query()->findOrFail($validated['customer_id']) : null;

        $reservation = new RestaurantReservation;
        $reservation->forceFill([
            ...$validated,
            'customer_name' => $validated['customer_name'] ?? $customer?->name,
            'customer_phone' => $validated['customer_phone'] ?? $customer?->phone,
            'status' => RestaurantReservation::CONFIRMED,
        ])->save();

        $this->scheduleHold($reservation);

        return $reservation->load('table.floor');
    }

    /**
     * A moved reservation schedules a new hold; the old job sees the change
     * and does nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateReservation(RestaurantReservation $reservation, array $data): RestaurantReservation
    {
        $this->assertStatus($reservation, [RestaurantReservation::CONFIRMED], 'updated');
        $validated = $this->validate($data, false);
        $before = [$reservation->restaurant_table_id, $reservation->reservation_time->getTimestamp()];

        $reservation->forceFill($validated)->save();

        if ([$reservation->restaurant_table_id, $reservation->reservation_time->getTimestamp()] !== $before) {
            $this->freeHeldTable((int) $before[0]);
            $this->scheduleHold($reservation);
        }

        return $reservation->load('table.floor');
    }

    /**
     * The party arrives: the table order opens and the reservation is seated.
     */
    public function seatReservation(RestaurantReservation $reservation, User $by): Order
    {
        return DB::connection('tenant')->transaction(function () use ($reservation, $by): Order {
            /** @var RestaurantReservation $locked */
            $locked = RestaurantReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertStatus($locked, [RestaurantReservation::CONFIRMED], 'seated');

            $order = $this->tableOrders->openTableOrder($locked->table, [], [
                'customer_id' => $locked->customer_id,
                'customer_name' => $locked->customer_name,
                'customer_phone' => $locked->customer_phone,
            ], $by);

            $locked->forceFill(['status' => RestaurantReservation::SEATED, 'order_id' => $order->id])->save();
            $reservation->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    public function cancelReservation(RestaurantReservation $reservation): RestaurantReservation
    {
        return $this->close($reservation, RestaurantReservation::CANCELLED);
    }

    public function markNoShow(RestaurantReservation $reservation): RestaurantReservation
    {
        return $this->close($reservation, RestaurantReservation::NO_SHOW);
    }

    /**
     * @param  array{date?: string, status?: string, table_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, RestaurantReservation>
     */
    public function listReservations(array $filters): LengthAwarePaginator
    {
        return RestaurantReservation::query()->with('table.floor:id,name')
            ->when(isset($filters['date']), static fn ($q) => $q->whereDate('reservation_time', $filters['date']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['table_id']), static fn ($q) => $q->where('restaurant_table_id', $filters['table_id']))
            ->orderBy('reservation_time')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Holds the table now when the reservation is within the hold window,
     * else when the window opens (a delayed job).
     */
    public function markTableReserved(RestaurantReservation $reservation): void
    {
        DB::connection('tenant')->transaction(function () use ($reservation): void {
            /** @var RestaurantTable|null $table */
            $table = RestaurantTable::query()->lockForUpdate()->find($reservation->restaurant_table_id);

            if ($table !== null && $table->status === RestaurantTable::AVAILABLE) {
                $table->forceFill(['status' => RestaurantTable::RESERVED])->save();
            }
        });
    }

    private function scheduleHold(RestaurantReservation $reservation): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $holdAt = CarbonImmutable::instance($reservation->reservation_time)->subMinutes(self::HOLD_MINUTES);

        if ($holdAt->lte(now())) {
            $this->markTableReserved($reservation);

            return;
        }

        MarkTableReserved::dispatch((string) $tenant->getTenantKey(), $reservation->id, $reservation->reservation_time->toIso8601String())->delay($holdAt)->afterCommit();
    }

    private function close(RestaurantReservation $reservation, string $status): RestaurantReservation
    {
        return DB::connection('tenant')->transaction(function () use ($reservation, $status): RestaurantReservation {
            /** @var RestaurantReservation $locked */
            $locked = RestaurantReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->assertStatus($locked, [RestaurantReservation::CONFIRMED], $status);

            $locked->forceFill(['status' => $status])->save();
            $this->freeHeldTable($locked->restaurant_table_id);

            return $locked->load('table.floor');
        });
    }

    /**
     * A held table goes back to available unless another reservation is
     * inside its hold window.
     */
    private function freeHeldTable(int $tableId): void
    {
        $held = RestaurantReservation::query()->where('restaurant_table_id', $tableId)->where('status', RestaurantReservation::CONFIRMED)
            ->where('reservation_time', '<=', now()->addMinutes(self::HOLD_MINUTES))->exists();

        if (! $held) {
            RestaurantTable::query()->whereKey($tableId)->where('status', RestaurantTable::RESERVED)->update(['status' => RestaurantTable::AVAILABLE, 'updated_at' => now()]);
        }
    }

    /**
     * @param  list<string>  $from
     */
    private function assertStatus(RestaurantReservation $reservation, array $from, string $to): void
    {
        if (! in_array($reservation->status, $from, true)) {
            throw ApiException::invalidTransition($reservation->status, $to);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return Validator::make($data, [
            'restaurant_table_id' => [$required, 'integer', Rule::exists('tenant.restaurant_tables', 'id')],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.customers', 'id')->whereNull('deleted_at')],
            'customer_name' => [$creating ? 'required_without:customer_id' : 'sometimes', 'nullable', 'string', 'max:120'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'party_size' => [$required, 'integer', 'min:1', 'max:500'],
            'reservation_time' => [$required, 'date', 'after:now'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
    }
}
