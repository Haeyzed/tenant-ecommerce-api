<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\DeliveryAssignment;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryAssignmentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Driver assignment (spec §36.4): shipments.assign-driver and
 * delivery-assignments.reassign.
 */
final class DeliveryAssignmentController extends Controller
{
    public function __construct(
        private readonly DeliveryAssignmentService $assignments,
        private readonly ShippingPresenter $presenter,
    ) {}

    public function assign(Request $request, Shipment $shipment): JsonResponse
    {
        $assignment = $this->assignments->assignDriver($shipment, $this->driver($request));

        return APIResponse::created($this->presenter->assignment($assignment->load('driver')), 'Driver assigned');
    }

    public function reassign(Request $request, DeliveryAssignment $assignment): JsonResponse
    {
        return APIResponse::success($this->presenter->assignment($this->assignments->reassignDriver($assignment, $this->driver($request))->load('driver')), 'Driver reassigned');
    }

    private function driver(Request $request): Driver
    {
        $id = (int) $request->validate(['driver_id' => ['required', 'integer', Rule::exists('tenant.drivers', 'id')]])['driver_id'];

        return Driver::query()->findOrFail($id);
    }
}
