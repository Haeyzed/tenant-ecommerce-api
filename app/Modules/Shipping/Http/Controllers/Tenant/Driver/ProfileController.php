<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant\Driver;

use App\Http\Controllers\Controller;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Shipping\Services\DriverService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in driver's own profile and availability (spec §36.4).
 */
final class ProfileController extends Controller
{
    public function __construct(
        private readonly DriverService $drivers,
        private readonly ShippingPresenter $presenter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return APIResponse::success($this->presenter->driver($this->driver($request)));
    }

    public function availability(Request $request): JsonResponse
    {
        $available = $request->validate(['is_available' => ['required', 'boolean']])['is_available'];

        return APIResponse::success($this->presenter->driver($this->drivers->setAvailability($this->driver($request), (bool) $available)), 'Availability updated');
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
