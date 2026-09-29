<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\Concerns\ResolvesActingEmployee;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Services\HrAttendanceService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Attendance (spec §58.3). Clock-in and clock-out are self-service; with
 * hr.attendance.record, staff record for any employee (a shared kiosk).
 */
final class AttendanceController extends Controller
{
    use ResolvesActingEmployee;

    public const string RECORD = 'hr.attendance.record';

    public function __construct(
        private readonly HrAttendanceService $attendance,
        private readonly HrPresenter $presenter,
    ) {}

    /**
     * Body: employee_id? (with hr.attendance.record), notes?
     */
    public function clockIn(Request $request): JsonResponse
    {
        $notes = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:255']])['notes'] ?? null;

        return APIResponse::created($this->presenter->attendance($this->attendance->clockIn($this->actingEmployee($request, self::RECORD), $notes)), 'Clocked in');
    }

    /**
     * Body: employee_id? (with hr.attendance.record).
     */
    public function clockOut(Request $request): JsonResponse
    {
        return APIResponse::success($this->presenter->attendance($this->attendance->clockOut($this->actingEmployee($request, self::RECORD))), 'Clocked out');
    }

    /**
     * Query: from, to (dates; at most a year).
     */
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return APIResponse::success(['from' => $from, 'to' => $to, 'employees' => $this->attendance->getAttendanceSummary($from, $to)]);
    }

    public function forEmployee(Request $request, HrEmployee $employee): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return APIResponse::success($this->attendance->getAttendanceForEmployee($employee, $from, $to)->map(fn (HrAttendance $row): array => $this->presenter->attendance($row))->all());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $validated['to'] ?? today()->toDateString();
        $from = $validated['from'] ?? today()->subDays(29)->toDateString();

        if (now()->parse($from)->diffInDays(now()->parse($to)) > 366) {
            $from = now()->parse($to)->subDays(366)->toDateString();
        }

        return [$from, $to];
    }
}
