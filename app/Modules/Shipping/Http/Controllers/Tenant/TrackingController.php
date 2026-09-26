<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\OrderAccess;
use App\Modules\Orders\Models\Order;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/orders/{order}/tracking (spec §36.4): one entry per shipment,
 * with its items, carrier data and in-house status trail.
 */
final class TrackingController extends Controller
{
    public function show(Request $request, Order $order, ShipmentService $shipments, ShippingPresenter $presenter): JsonResponse
    {
        OrderAccess::assertCanView($request, $order);

        return APIResponse::success([
            'order_number' => $order->order_number,
            'status' => $order->status,
            'shipments' => $shipments->forOrder($order)
                ->reject(static fn (Shipment $s): bool => $s->status === Shipment::CANCELLED)
                ->map(static fn (Shipment $s): array => $presenter->shipment($s, true))->values(),
        ]);
    }
}
