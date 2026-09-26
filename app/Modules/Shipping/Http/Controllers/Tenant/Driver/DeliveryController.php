<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Driver;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\DeliveryAssignment;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Services\DeliveryAssignmentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The signed-in driver's deliveries (spec §36.4). Only the driver's own
 * assignments are ever found (§12.5).
 */
final class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryAssignmentService $assignments,
        private readonly ShippingPresenter $presenter,
    ) {}

    /**
     * Open deliveries by default (?status= for one status).
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['sometimes', Rule::in([DeliveryAssignment::ASSIGNED, DeliveryAssignment::PICKED_UP, DeliveryAssignment::EN_ROUTE, DeliveryAssignment::DELIVERED, DeliveryAssignment::FAILED])]])['status'] ?? null;

        $rows = DeliveryAssignment::query()
            ->with(['shipment.order', 'shipment.warehouse:id,name', 'shipment.items.orderItem:id,name_snapshot'])
            ->where('driver_id', $this->driver($request)->id)
            ->when($status !== null,
                static fn ($q) => $q->where('status', $status),
                static fn ($q) => $q->whereIn('status', [DeliveryAssignment::ASSIGNED, DeliveryAssignment::PICKED_UP, DeliveryAssignment::EN_ROUTE]))
            ->orderBy('assigned_at')
            ->limit(200)
            ->get();

        return APIResponse::success($rows->map(fn (DeliveryAssignment $a): array => $this->presenter->delivery($a))->values());
    }

    public function pickUp(Request $request, int $assignment): JsonResponse
    {
        return $this->respond($this->assignments->markPickedUp($this->find($request, $assignment)), 'Picked up');
    }

    public function enRoute(Request $request, int $assignment): JsonResponse
    {
        return $this->respond($this->assignments->markEnRoute($this->find($request, $assignment)), 'On the way');
    }

    public function delivered(Request $request, int $assignment): JsonResponse
    {
        $request->validate(['proof' => ['sometimes', 'nullable', 'file']]);
        $proof = $request->file('proof');

        return $this->respond($this->assignments->markDelivered($this->find($request, $assignment), $proof instanceof UploadedFile ? $proof : null), 'Delivered');
    }

    public function failed(Request $request, int $assignment): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->respond($this->assignments->markFailed($this->find($request, $assignment), $reason), 'Delivery failed');
    }

    private function respond(DeliveryAssignment $assignment, string $message): JsonResponse
    {
        return APIResponse::success($this->presenter->delivery($assignment->load(['shipment.order', 'shipment.warehouse:id,name', 'shipment.items.orderItem:id,name_snapshot'])), $message);
    }

    private function find(Request $request, int $id): DeliveryAssignment
    {
        /** @var DeliveryAssignment */
        return DeliveryAssignment::query()->where('driver_id', $this->driver($request)->id)->whereKey($id)->firstOrFail();
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
