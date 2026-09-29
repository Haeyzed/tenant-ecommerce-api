<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\RestaurantPresenter;
use App\Modules\Restaurant\Models\RestaurantFloor;
use App\Modules\Restaurant\Services\RestaurantFloorService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Floors (spec §65.5).
 */
final class FloorController extends Controller
{
    public function __construct(
        private readonly RestaurantFloorService $floors,
        private readonly RestaurantPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->floors->listFloors()->map(fn (RestaurantFloor $f): array => $this->presenter->floor($f))->values()->all());
    }

    /**
     * Body: name, warehouse_id, sort_order?
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->floor($this->floors->createFloor($request->only(['name', 'warehouse_id', 'sort_order']))), 'Floor created');
    }

    public function update(Request $request, RestaurantFloor $floor): JsonResponse
    {
        return APIResponse::success($this->presenter->floor($this->floors->updateFloor($floor, $request->only(['name', 'warehouse_id', 'sort_order']))), 'Floor updated');
    }

    public function destroy(RestaurantFloor $floor): JsonResponse
    {
        $this->floors->deleteFloor($floor);

        return APIResponse::success(null, 'Floor deleted');
    }
}
