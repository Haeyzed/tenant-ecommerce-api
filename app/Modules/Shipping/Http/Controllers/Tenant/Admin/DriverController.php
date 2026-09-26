<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Services\DriverService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Drivers (spec §36.4). DELETE deactivates: assignments keep the driver.
 */
final class DriverController extends Controller
{
    public function __construct(
        private readonly DriverService $drivers,
        private readonly ShippingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([Driver::ACTIVE, Driver::INACTIVE])],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_available', $filters)) {
            $filters['is_available'] = $request->boolean('is_available');
        }

        return APIResponse::success($this->drivers->listDrivers($filters)->map(fn (Driver $d): array => $this->presenter->driver($d))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->driver($this->drivers->createDriver($request->all())), 'Driver created');
    }

    public function update(Request $request, Driver $driver): JsonResponse
    {
        return APIResponse::success($this->presenter->driver($this->drivers->updateDriver($driver, $request->all())), 'Driver updated');
    }

    public function destroy(Driver $driver): JsonResponse
    {
        return APIResponse::success($this->presenter->driver($this->drivers->deactivateDriver($driver)), 'Driver deactivated');
    }

    public function availability(Request $request, Driver $driver): JsonResponse
    {
        $available = $request->validate(['is_available' => ['required', 'boolean']])['is_available'];

        return APIResponse::success($this->presenter->driver($this->drivers->setAvailability($driver, (bool) $available)), 'Availability updated');
    }
}
