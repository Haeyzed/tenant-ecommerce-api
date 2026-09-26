<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stock transfers (spec §33.3).
 */
final class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly WarehouseService $warehouses,
        private readonly InventoryPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(StockTransfer::STATUSES)],
            'warehouse_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->transfers->listTransfers($filters, $this->viewer($request))
            ->through(fn (StockTransfer $t): array => $this->presenter->transfer($t, false)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'to_warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['required', 'array'],
        ]);

        $from = Warehouse::query()->findOrFail($validated['from_warehouse_id']);
        $to = Warehouse::query()->findOrFail($validated['to_warehouse_id']);
        $viewer = $this->viewer($request);
        $this->warehouses->assertVisible($from, $viewer);

        $transfer = $this->transfers->createTransfer($from, $to, $validated['items'], $validated['notes'] ?? null, $viewer);

        return APIResponse::created($this->presenter->transfer($this->transfers->getTransfer($transfer), true), 'Transfer created');
    }

    public function show(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->transfers->assertVisible($transfer, $this->viewer($request));

        return APIResponse::success($this->presenter->transfer($this->transfers->getTransfer($transfer), true));
    }

    public function update(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->transfers->assertVisible($transfer, $this->viewer($request));
        $validated = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:5000'], 'items' => ['sometimes', 'array']]);

        return APIResponse::success($this->presenter->transfer(
            $this->transfers->updateTransfer($transfer, $validated['items'] ?? null, array_key_exists('notes', $validated) ? (string) $validated['notes'] : null), true,
        ), 'Transfer updated');
    }

    public function dispatch(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->transfers->assertVisible($transfer, $this->viewer($request));

        return APIResponse::success($this->presenter->transfer($this->transfers->dispatchTransfer($transfer), true), 'Transfer dispatched');
    }

    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->transfers->assertVisible($transfer, $this->viewer($request));

        return APIResponse::success($this->presenter->transfer($this->transfers->receiveTransfer($transfer, (array) $request->input('items', [])), true), 'Transfer received');
    }

    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->transfers->assertVisible($transfer, $this->viewer($request));

        return APIResponse::success($this->presenter->transfer($this->transfers->cancelTransfer($transfer), true), 'Transfer cancelled');
    }

    private function viewer(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
