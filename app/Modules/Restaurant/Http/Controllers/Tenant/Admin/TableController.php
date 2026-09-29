<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\RestaurantPresenter;
use App\Modules\Restaurant\Models\RestaurantFloor;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Restaurant\Services\RestaurantTableService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tables (spec §65.5), each listed with its open order.
 */
final class TableController extends Controller
{
    public function __construct(
        private readonly RestaurantTableService $tables,
        private readonly RestaurantPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'floor_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(RestaurantTable::STATUSES)],
        ]);

        return APIResponse::success($this->tables->listTables($filters)->map(fn (RestaurantTable $t): array => $this->presenter->table($t))->values()->all());
    }

    /**
     * Body: restaurant_floor_id, name, seats
     */
    public function store(Request $request): JsonResponse
    {
        $floorId = $request->validate(['restaurant_floor_id' => ['required', 'integer']])['restaurant_floor_id'];
        $table = $this->tables->createTable(RestaurantFloor::query()->findOrFail($floorId), $request->only(['name', 'seats']));

        return APIResponse::created($this->presenter->table($table), 'Table created');
    }

    public function update(Request $request, RestaurantTable $table): JsonResponse
    {
        return APIResponse::success($this->presenter->table($this->tables->updateTable($table, $request->only(['name', 'seats', 'restaurant_floor_id']))), 'Table updated');
    }

    /**
     * Body: status (available | cleaning)
     */
    public function updateStatus(Request $request, RestaurantTable $table): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'string']])['status'];

        return APIResponse::success($this->presenter->table($this->tables->updateStatus($table, $status)), 'Table status updated');
    }

    public function destroy(RestaurantTable $table): JsonResponse
    {
        $this->tables->deleteTable($table);

        return APIResponse::success(null, 'Table deleted');
    }
}
