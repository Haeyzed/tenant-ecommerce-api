<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Http\BookingPresenter;
use App\Modules\Booking\Models\BookingStaff;
use App\Modules\Booking\Models\BookingTimeOff;
use App\Modules\Booking\Services\BookingAvailabilityService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One-off absences (spec §66.3).
 */
final class TimeOffController extends Controller
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingPresenter $presenter,
    ) {}

    /**
     * Body: start_datetime, end_datetime (tenant local unless an offset is given), reason?
     */
    public function store(Request $request, BookingStaff $staff): JsonResponse
    {
        return APIResponse::created($this->presenter->timeOff($this->availability->addTimeOff($staff, $request->only(['start_datetime', 'end_datetime', 'reason']))), 'Time off added');
    }

    public function destroy(BookingStaff $staff, BookingTimeOff $timeOff): JsonResponse
    {
        if ($timeOff->booking_staff_id !== $staff->id) {
            throw new NotFoundHttpException('Not found.');
        }

        $this->availability->removeTimeOff($timeOff);

        return APIResponse::success(null, 'Time off removed');
    }
}
