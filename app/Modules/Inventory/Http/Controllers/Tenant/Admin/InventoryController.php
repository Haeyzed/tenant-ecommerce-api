<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stock levels and the movement ledger (spec §32.10): GET inventory
 * (inventory.view), low-stock, out-of-stock and movements. A staff user
 * narrowed to their warehouses must name one of them, and sees only its
 * movements.
 */
final class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly WarehouseService $warehouses,
        private readonly InventoryPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$warehouse, $filters] = $this->levelFilters($request);

        return APIResponse::success($this->inventory->getStockLevels($warehouse, $filters)->through(fn (object $row): array => $this->presenter->stockLevel($row)));
    }

    public function lowStock(Request $request): JsonResponse
    {
        [$warehouse, $filters] = $this->levelFilters($request);

        return APIResponse::success($this->inventory->getLowStockProducts($warehouse, $filters)->through(fn (object $row): array => $this->presenter->stockLevel($row)));
    }

    public function outOfStock(Request $request): JsonResponse
    {
        [$warehouse, $filters] = $this->levelFilters($request);

        return APIResponse::success($this->inventory->getOutOfStockProducts($warehouse, $filters)->through(fn (object $row): array => $this->presenter->stockLevel($row)));
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'warehouse_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
            'product_variant_id' => ['sometimes', 'integer'],
            'movement_type' => ['sometimes', Rule::in(InventoryMovement::TYPES)],
            'reference_type' => ['sometimes', 'string', 'max:64'],
            'reference_id' => ['sometimes', 'integer', 'required_with:reference_type'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $visible = $this->warehouses->visibleIds($this->viewer($request));

        $page = InventoryMovement::query()
            ->with(['warehouse:id,name', 'product:id,name', 'variant:id,sku', 'user:id,name'])
            ->when($visible !== null, static fn (Builder $q) => $q->whereIn('warehouse_id', $visible))
            ->when($filters['warehouse_id'] ?? null, static fn (Builder $q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['product_id'] ?? null, static fn (Builder $q, $v) => $q->where('product_id', $v))
            ->when($filters['product_variant_id'] ?? null, static fn (Builder $q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['movement_type'] ?? null, static fn (Builder $q, $v) => $q->where('movement_type', $v))
            ->when($filters['reference_type'] ?? null, static fn (Builder $q, $v) => $q->where('reference_type', $v)->where('reference_id', $filters['reference_id']))
            ->when($filters['from'] ?? null, static fn (Builder $q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, static fn (Builder $q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success($page->through(fn (InventoryMovement $m): array => $this->presenter->movement($m)));
    }

    /**
     * @return array{0: Warehouse|null, 1: array{search?: string, per_page?: int}}
     */
    private function levelFilters(Request $request): array
    {
        $filters = $request->validate([
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $warehouse = isset($filters['warehouse_id']) ? Warehouse::query()->findOrFail((int) $filters['warehouse_id']) : null;
        $viewer = $this->viewer($request);

        if ($warehouse !== null) {
            $this->warehouses->assertVisible($warehouse, $viewer);
        } elseif ($this->warehouses->visibleIds($viewer) !== null) {
            // Totals across warehouses would include ones the user cannot see.
            throw ApiException::unprocessable('warehouse_required', 'Choose one of your warehouses.');
        }

        return [$warehouse, array_intersect_key($filters, array_flip(['search', 'per_page']))];
    }

    private function viewer(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
