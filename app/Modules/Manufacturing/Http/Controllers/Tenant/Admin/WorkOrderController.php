<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Manufacturing\Http\ManufacturingPresenter;
use App\Modules\Manufacturing\Models\BillOfMaterial;
use App\Modules\Manufacturing\Models\WorkOrder;
use App\Modules\Manufacturing\Services\WorkOrderService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Work orders (spec §64.4). Staff narrowed by data-access scope (§25.3) see
 * the orders of their warehouses (or those they created).
 */
final class WorkOrderController extends Controller
{
    public function __construct(
        private readonly WorkOrderService $orders,
        private readonly ManufacturingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(WorkOrder::STATUSES)],
            'warehouse_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->orders->listWorkOrders($filters, $this->user($request))->through(fn (WorkOrder $o): array => $this->presenter->workOrder($o)));
    }

    /**
     * Body: bill_of_material_id, warehouse_id, quantity_to_produce, scheduled_start_date?, scheduled_end_date?, notes?
     */
    public function store(Request $request): JsonResponse
    {
        $ids = $request->validate(['bill_of_material_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'], 'quantity_to_produce' => ['required', 'numeric']]);
        $order = $this->orders->createWorkOrder(BillOfMaterial::query()->findOrFail((int) $ids['bill_of_material_id']), Warehouse::query()->findOrFail((int) $ids['warehouse_id']),
            (string) $ids['quantity_to_produce'], $request->only(['scheduled_start_date', 'scheduled_end_date', 'notes']), $this->user($request));

        return APIResponse::created($this->presenter->workOrder($order), 'Work order created');
    }

    public function show(Request $request, WorkOrder $order): JsonResponse
    {
        return APIResponse::success($this->presenter->workOrder($this->orders->getWorkOrder($order, $this->user($request))));
    }

    public function start(Request $request, WorkOrder $order): JsonResponse
    {
        return APIResponse::success($this->presenter->workOrder($this->orders->startWorkOrder($this->orders->getWorkOrder($order, $this->user($request)))), 'Production started: components reserved');
    }

    public function complete(Request $request, WorkOrder $order): JsonResponse
    {
        return APIResponse::success($this->presenter->workOrder($this->orders->completeWorkOrder($this->orders->getWorkOrder($order, $this->user($request)))), 'Production completed');
    }

    public function cancel(Request $request, WorkOrder $order): JsonResponse
    {
        return APIResponse::success($this->presenter->workOrder($this->orders->cancelWorkOrder($this->orders->getWorkOrder($order, $this->user($request)))), 'Work order cancelled');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
