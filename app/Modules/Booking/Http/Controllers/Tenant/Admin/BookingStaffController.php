<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\BookingPresenter;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Booking\Services\BookingAvailabilityService;
use App\Modules\Booking\Services\BookingStaffService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bookable staff, their weekly hours and the services they perform
 * (spec §66.3).
 */
final class BookingStaffController extends Controller
{
    public function __construct(
        private readonly BookingStaffService $staff,
        private readonly BookingAvailabilityService $availability,
        private readonly BookingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['product_id' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']]);

        return APIResponse::success($this->staff->listStaff($filters)->map(fn (BookingStaff $s): array => $this->presenter->staff($s))->values()->all());
    }

    /**
     * Body: user_id
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->validate(['user_id' => ['required', 'integer']])['user_id'];

        return APIResponse::created($this->presenter->staff($this->staff->createStaff(User::query()->findOrFail($userId))), 'Staff member is now bookable');
    }

    /**
     * Body: is_active
     */
    public function update(Request $request, BookingStaff $staff): JsonResponse
    {
        $data = $request->validate(['is_active' => ['sometimes', 'boolean']]);

        return APIResponse::success($this->presenter->staff($this->staff->updateStaff($staff, $data)), 'Staff member updated');
    }

    public function destroy(BookingStaff $staff): JsonResponse
    {
        return APIResponse::success($this->presenter->staff($this->staff->deactivateStaff($staff)), 'Staff member deactivated');
    }

    /**
     * Body: slots[] (day_of_week, start_time H:i, end_time H:i); replaces every window.
     */
    public function availability(Request $request, BookingStaff $staff): JsonResponse
    {
        return APIResponse::success($this->presenter->staff($this->availability->setWeeklyAvailability($staff, array_values((array) $request->input('slots', [])))), 'Weekly hours saved');
    }

    public function attach(Product $product, BookingStaff $staff): JsonResponse
    {
        $this->staff->attachToProduct($product, $staff);

        return APIResponse::success(null, 'Staff member assigned to the service');
    }

    public function detach(Product $product, BookingStaff $staff): JsonResponse
    {
        $this->staff->detachFromProduct($product, $staff);

        return APIResponse::success(null, 'Staff member removed from the service');
    }
}
