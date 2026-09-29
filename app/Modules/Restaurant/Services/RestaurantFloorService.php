<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Restaurant\Models\RestaurantFloor;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Dining areas (spec §65.1, §65.5).
 */
final readonly class RestaurantFloorService
{
    /**
     * @param  array<string, mixed>  $data  name, warehouse_id, sort_order?
     */
    public function createFloor(array $data): RestaurantFloor
    {
        $floor = new RestaurantFloor;
        $floor->forceFill($this->validate($data, true))->save();

        return $floor->load('warehouse:id,name')->loadCount('tables');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateFloor(RestaurantFloor $floor, array $data): RestaurantFloor
    {
        $validated = $this->validate($data, false);

        if (isset($validated['warehouse_id']) && (int) $validated['warehouse_id'] !== $floor->warehouse_id
            && RestaurantTable::query()->where('restaurant_floor_id', $floor->id)->where('status', RestaurantTable::OCCUPIED)->exists()) {
            throw ApiException::unprocessable('floor_in_service', 'Settle the open table orders before moving this floor to another location.');
        }

        $floor->forceFill($validated)->save();

        return $floor->load('warehouse:id,name')->loadCount('tables');
    }

    public function deleteFloor(RestaurantFloor $floor): void
    {
        if (RestaurantTable::query()->where('restaurant_floor_id', $floor->id)->exists()) {
            throw ApiException::unprocessable('floor_has_tables', 'Remove this floor\'s tables first.');
        }

        $floor->delete();
    }

    /**
     * @return Collection<int, RestaurantFloor>
     */
    public function listFloors(): Collection
    {
        return RestaurantFloor::query()->with('warehouse:id,name')->withCount('tables')->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $validated = Validator::make($data, [
            'name' => [$required, 'string', 'max:80'],
            'warehouse_id' => [$required, 'integer', Rule::exists('tenant.warehouses', 'id')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ])->validate();

        if (isset($validated['warehouse_id']) && ! Warehouse::query()->whereKey($validated['warehouse_id'])->where('is_active', true)->exists()) {
            throw ApiException::unprocessable('warehouse_inactive', 'Choose an active location.');
        }

        return $validated;
    }
}
