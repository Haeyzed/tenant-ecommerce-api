<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Services\ShippingZoneService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shipping zones (spec §36.4).
 */
final class ShippingZoneController extends Controller
{
    public function __construct(
        private readonly ShippingZoneService $zones,
        private readonly ShippingPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->zones->listZones()->map(fn (ShippingZone $z): array => $this->presenter->zone($z))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->zone($this->zones->createZone($request->all())), 'Shipping zone created');
    }

    public function update(Request $request, ShippingZone $zone): JsonResponse
    {
        return APIResponse::success($this->presenter->zone($this->zones->updateZone($zone, $request->all())), 'Shipping zone updated');
    }

    public function destroy(ShippingZone $zone): JsonResponse
    {
        $this->zones->deleteZone($zone);

        return APIResponse::noContent('Shipping zone deleted');
    }
}
