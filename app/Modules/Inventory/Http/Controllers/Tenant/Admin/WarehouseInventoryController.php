<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/warehouses/{warehouse}/inventory (spec §32.10): the
 * inventory rows of one warehouse (warehouses.inventory.view).
 */
final class WarehouseInventoryController extends Controller
{
    public function index(Request $request, Warehouse $warehouse, WarehouseService $warehouses, InventoryPresenter $presenter): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $warehouses->assertVisible($warehouse, $viewer);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $page = Inventory::query()
            ->with(['product:id,name,sku', 'variant:id,sku'])
            ->where('warehouse_id', $warehouse->id)
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w
                ->whereHas('product', static fn (Builder $p) => $p->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('sku', $search))
                ->orWhereHas('variant', static fn (Builder $v) => $v->where('sku', $search))))
            ->orderBy('product_id')
            ->orderBy('variant_key')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success($page->through(static fn (Inventory $row): array => $presenter->stockRow($row)));
    }
}
