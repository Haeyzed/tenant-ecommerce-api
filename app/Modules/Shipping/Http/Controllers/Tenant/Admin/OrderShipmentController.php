<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An order's shipments (spec §36.4): GET and POST
 * /api/admin/orders/{order}/shipments (orders.shipments.view / .create).
 * {all: true} creates one shipment per warehouse for everything unshipped.
 */
final class OrderShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ShippingPresenter $presenter,
    ) {}

    public function index(Order $order): JsonResponse
    {
        return APIResponse::success($this->shipments->forOrder($order)->map(fn (Shipment $s): array => $this->presenter->shipment($s))->values());
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        /** @var User $by */
        $by = $request->user();

        if ($request->boolean('all')) {
            $created = $this->shipments->createShipmentsForOrder($order, $by);

            return APIResponse::created($created->map(fn (Shipment $s): array => $this->presenter->shipment($s->load(['items.orderItem', 'warehouse'])))->values(), 'Shipments created');
        }

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'shipping_method_id' => ['sometimes', 'nullable', 'integer'],
            'carrier' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'tracking_url' => ['sometimes', 'nullable', 'url:https,http', 'max:255'],
        ]);

        $shipment = $this->shipments->createShipment($order, Warehouse::query()->findOrFail($validated['warehouse_id']), $validated['items'],
            array_intersect_key($validated, array_flip(['shipping_method_id', 'carrier', 'tracking_number', 'tracking_url'])), $by);

        return APIResponse::created($this->presenter->shipment($shipment->load(['items.orderItem', 'warehouse'])), 'Shipment created');
    }
}
