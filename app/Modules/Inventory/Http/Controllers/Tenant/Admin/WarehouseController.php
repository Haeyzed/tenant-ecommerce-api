<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Warehouses (spec §32.10). A staff user narrowed to their warehouses
 * (§25.3) sees and manages only those.
 */
final class WarehouseController extends Controller
{
    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly InventoryPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['sometimes', 'string', 'max:100'], 'is_active' => ['sometimes', 'boolean']]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->warehouses->listWarehouses($filters, $this->viewer($request))
            ->map(fn (Warehouse $w): array => $this->presenter->warehouse($w))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->warehouse($this->warehouses->createWarehouse($request->all())), 'Warehouse created');
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->warehouses->assertVisible($warehouse, $this->viewer($request));

        return APIResponse::success($this->presenter->warehouse($this->warehouses->updateWarehouse($warehouse, $request->all())), 'Warehouse updated');
    }

    public function deactivate(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->warehouses->assertVisible($warehouse, $this->viewer($request));

        return APIResponse::success($this->presenter->warehouse($this->warehouses->deactivateWarehouse($warehouse)), 'Warehouse deactivated');
    }

    public function activate(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->warehouses->assertVisible($warehouse, $this->viewer($request));

        return APIResponse::success($this->presenter->warehouse($this->warehouses->activateWarehouse($warehouse)), 'Warehouse activated');
    }

    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->warehouses->assertVisible($warehouse, $this->viewer($request));
        $this->warehouses->deleteWarehouse($warehouse);

        return APIResponse::noContent('Warehouse deleted');
    }

    private function viewer(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
