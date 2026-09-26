<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shipments (spec §36.4). A staff user narrowed to warehouses sees and
 * moves only their warehouses' shipments.
 */
final class ShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly WarehouseService $warehouses,
        private readonly ShippingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Shipment::STATUSES)],
            'warehouse_id' => ['sometimes', 'integer'],
            'driver_id' => ['sometimes', 'integer'],
            'order_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->shipments->listShipments($filters, $this->actor($request))->through(fn (Shipment $s): array => $this->presenter->shipment($s)));
    }

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertVisible($request, $shipment);

        return APIResponse::success($this->present($this->shipments->updateTracking($shipment, $request->all())), 'Tracking updated');
    }

    public function dispatch(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertVisible($request, $shipment);

        return APIResponse::success($this->present($this->shipments->markDispatched($shipment)), 'Shipment dispatched');
    }

    public function inTransit(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertVisible($request, $shipment);

        return APIResponse::success($this->present($this->shipments->markInTransit($shipment)), 'Shipment in transit');
    }

    public function delivered(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertVisible($request, $shipment);

        return APIResponse::success($this->present($this->shipments->markDelivered($shipment)), 'Shipment delivered');
    }

    public function cancel(Request $request, Shipment $shipment): JsonResponse
    {
        $this->assertVisible($request, $shipment);

        return APIResponse::success($this->present($this->shipments->cancelShipment($shipment)), 'Shipment cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Shipment $shipment): array
    {
        return $this->presenter->shipment($shipment->load(['items.orderItem', 'warehouse', 'order', 'assignment.driver']));
    }

    private function assertVisible(Request $request, Shipment $shipment): void
    {
        $visible = $this->warehouses->visibleIds($this->actor($request));

        if ($visible !== null && ! in_array($shipment->warehouse_id, $visible, true)) {
            throw (new ModelNotFoundException)->setModel(Shipment::class, [$shipment->id]);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
