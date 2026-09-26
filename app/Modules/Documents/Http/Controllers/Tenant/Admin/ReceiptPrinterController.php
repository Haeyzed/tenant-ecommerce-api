<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\DocumentPresenter;
use App\Modules\Documents\Models\ReceiptPrinter;
use App\Modules\Documents\Services\ReceiptPrinterService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receipt printers (spec §43.6, §43.7), scoped to the staff member's
 * warehouses.
 */
final class ReceiptPrinterController extends Controller
{
    public function __construct(
        private readonly ReceiptPrinterService $printers,
        private readonly WarehouseService $warehouses,
        private readonly DocumentPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->validate(['warehouse_id' => ['sometimes', 'integer']])['warehouse_id'] ?? null;
        $printers = $this->printers->listPrinters($this->warehouses->visibleIds($this->actor($request)), $warehouseId === null ? null : (int) $warehouseId);

        return APIResponse::success($printers->map(fn (ReceiptPrinter $p): array => $this->presenter->printer($p))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->printers->rules(true));
        $this->assertWarehouse($request, (int) $validated['warehouse_id']);

        return APIResponse::created($this->presenter->printer($this->printers->createPrinter($validated)->load('warehouse:id,name')), 'Printer created');
    }

    public function update(Request $request, ReceiptPrinter $printer): JsonResponse
    {
        $this->assertWarehouse($request, $printer->warehouse_id);
        $validated = $request->validate($this->printers->rules(false, $printer));

        if (isset($validated['warehouse_id'])) {
            $this->assertWarehouse($request, (int) $validated['warehouse_id']);
        }

        return APIResponse::success($this->presenter->printer($this->printers->updatePrinter($printer, $validated)->load('warehouse:id,name')), 'Printer updated');
    }

    public function destroy(Request $request, ReceiptPrinter $printer): JsonResponse
    {
        $this->assertWarehouse($request, $printer->warehouse_id);
        $this->printers->deletePrinter($printer);

        return APIResponse::success(null, 'Printer deleted');
    }

    public function activate(Request $request, ReceiptPrinter $printer): JsonResponse
    {
        $this->assertWarehouse($request, $printer->warehouse_id);
        $this->printers->activatePrinter($printer);

        return APIResponse::success($this->presenter->printer($printer->load('warehouse:id,name')), 'Printer activated');
    }

    public function deactivate(Request $request, ReceiptPrinter $printer): JsonResponse
    {
        $this->assertWarehouse($request, $printer->warehouse_id);
        $this->printers->deactivatePrinter($printer);

        return APIResponse::success($this->presenter->printer($printer->load('warehouse:id,name')), 'Printer deactivated');
    }

    private function assertWarehouse(Request $request, int $warehouseId): void
    {
        $this->warehouses->assertVisible(Warehouse::query()->findOrFail($warehouseId), $this->actor($request));
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
