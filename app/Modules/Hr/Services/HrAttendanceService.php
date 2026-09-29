<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrSettings;
use App\Modules\Hr\Models\HrShift;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Clock-in and clock-out (spec §58.3, §58.3a): one row per employee per
 * work date in the tenant timezone. A rostered shift sets the work date
 * (a night shift keeps its start date), the expected hours and the grace;
 * without one the applicable HR settings apply. Clock-out closes the open
 * row, measures worked time and, when enabled, records overtime for
 * approval.
 */
final readonly class HrAttendanceService
{
    /** A row left open longer than this is not closed by a clock-out. */
    private const int MAX_OPEN_HOURS = 24;

    public function __construct(
        private HrSettingsService $settings,
        private TenantSettingsService $tenantSettings,
        private HrShiftService $shifts,
    ) {}

    public function clockIn(HrEmployee $employee, ?string $notes = null): HrAttendance
    {
        $this->assertActive($employee);
        $now = $this->now();
        $rules = $this->settings->getApplicableSettings($employee);
        $assignment = $this->shifts->assignmentAt($employee, $now);
        $workDate = $assignment?->work_date->toDateString() ?? $now->toDateString();
        [$start] = $this->window($assignment?->shift, $rules, $workDate, $now);
        $grace = $assignment?->shift->late_grace_minutes ?? $rules->late_grace_minutes;

        try {
            $row = new HrAttendance;
            $row->forceFill([
                'employee_id' => $employee->id,
                'work_date' => $workDate,
                'shift_id' => $assignment?->shift_id,
                'clock_in_at' => $now->utc(),
                'is_late' => $now->greaterThan($start->addMinutes($grace)),
                'overtime_minutes' => 0,
                'overtime_status' => HrAttendance::OVERTIME_NONE,
                'notes' => $notes === null ? null : mb_substr($notes, 0, 255),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::unprocessable('already_clocked_in', 'This employee has already clocked in for this shift.');
        }

        return $row;
    }

    /**
     * Closes the employee's open row (today's, or a night shift's from
     * yesterday).
     */
    public function clockOut(HrEmployee $employee): HrAttendance
    {
        $now = $this->now();

        return DB::connection('tenant')->transaction(function () use ($employee, $now): HrAttendance {
            /** @var HrAttendance|null $row */
            $row = HrAttendance::query()->with('shift')->where('employee_id', $employee->id)->whereNull('clock_out_at')
                ->where('clock_in_at', '>=', $now->utc()->subHours(self::MAX_OPEN_HOURS))
                ->orderByDesc('clock_in_at')->lockForUpdate()->first();

            if ($row === null) {
                $closedToday = HrAttendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $now->toDateString())->whereNotNull('clock_out_at')->exists();

                throw $closedToday
                    ? ApiException::unprocessable('already_clocked_out', 'This employee has already clocked out today.')
                    : ApiException::unprocessable('not_clocked_in', 'This employee has not clocked in today.');
            }

            $rules = $this->settings->getApplicableSettings($employee);
            [$start, $end, $break] = $this->window($row->shift, $rules, $row->work_date->toDateString(), $now);
            $grace = $row->shift?->early_leave_grace_minutes ?? $rules->early_leave_grace_minutes;

            $scheduled = max(0, (int) $start->diffInMinutes($end) - $break);
            $onClock = max(0, (int) CarbonImmutable::parse($row->clock_in_at)->diffInMinutes($now));
            $worked = max(0, $onClock - $break);
            $extra = $worked - $scheduled;
            $overtime = $rules->overtime_enabled && $extra > 0 && $extra >= $rules->overtime_minimum_minutes ? $extra : 0;

            $row->forceFill([
                'clock_out_at' => $now->utc(),
                'is_early_leave' => $now->lessThan($end->subMinutes($grace)),
                'scheduled_minutes' => $scheduled,
                'worked_minutes' => $worked,
                'overtime_minutes' => $overtime,
                'overtime_status' => $overtime > 0 ? HrAttendance::OVERTIME_PENDING : HrAttendance::OVERTIME_NONE,
            ])->save();

            return $row;
        });
    }

    /**
     * Approves pending overtime, optionally fewer minutes than recorded.
     */
    public function approveOvertime(HrAttendance $row, ?int $minutes, User $by, ?string $note = null): HrAttendance
    {
        return $this->decideOvertime($row, HrAttendance::OVERTIME_APPROVED, $minutes, $by, $note);
    }

    public function rejectOvertime(HrAttendance $row, User $by, ?string $note = null): HrAttendance
    {
        return $this->decideOvertime($row, HrAttendance::OVERTIME_REJECTED, null, $by, $note);
    }

    /**
     * @param  array{status?: string, employee_id?: int, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, HrAttendance>
     */
    public function listOvertime(array $filters): LengthAwarePaginator
    {
        return HrAttendance::query()->with(['employee.user:id,name', 'shift', 'overtimeDecidedBy:id,name'])
            ->where('overtime_status', $filters['status'] ?? HrAttendance::OVERTIME_PENDING)
            ->when($filters['employee_id'] ?? null, static fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['from'] ?? null, static fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($filters['to'] ?? null, static fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->orderBy('work_date')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Approved overtime minutes per employee with a work date in the range.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, int> employee_id => minutes
     */
    public function approvedOvertimeMinutes(array $employeeIds, string $from, string $to): array
    {
        return HrAttendance::query()->whereIn('employee_id', $employeeIds)
            ->where('overtime_status', HrAttendance::OVERTIME_APPROVED)
            ->whereBetween('work_date', [$from, $to])
            ->groupBy('employee_id')->selectRaw('employee_id, SUM(overtime_approved_minutes) as minutes')
            ->pluck('minutes', 'employee_id')->map(static fn ($m): int => (int) $m)->all();
    }

    /**
     * @return Collection<int, HrAttendance>
     */
    public function getAttendanceForEmployee(HrEmployee $employee, string $from, string $to): Collection
    {
        return HrAttendance::query()->with('shift')->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get();
    }

    /**
     * Per employee: days present, late arrivals, early departures, worked
     * hours and overtime in the range.
     *
     * @return list<array{employee_id: int, employee_name: string, days_present: int, late: int, early_leave: int, worked_minutes: int, overtime_pending_minutes: int, overtime_approved_minutes: int}>
     */
    public function getAttendanceSummary(string $from, string $to): array
    {
        $rows = DB::connection('tenant')->table('hr_attendance')->whereBetween('work_date', [$from, $to])
            ->selectRaw('employee_id, COUNT(*) as days_present, SUM(is_late) as late, SUM(is_early_leave) as early_leave, COALESCE(SUM(worked_minutes), 0) as worked')
            ->selectRaw("COALESCE(SUM(CASE WHEN overtime_status = 'pending' THEN overtime_minutes END), 0) as ot_pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN overtime_status = 'approved' THEN overtime_approved_minutes END), 0) as ot_approved")
            ->groupBy('employee_id')->get()->keyBy('employee_id');

        return HrEmployee::query()->withTrashed()->with('user:id,name')->whereKey($rows->keys())->orderBy('id')->get()
            ->map(static fn (HrEmployee $e): array => [
                'employee_id' => $e->id,
                'employee_name' => $e->displayName(),
                'days_present' => (int) $rows[$e->id]->days_present,
                'late' => (int) $rows[$e->id]->late,
                'early_leave' => (int) $rows[$e->id]->early_leave,
                'worked_minutes' => (int) $rows[$e->id]->worked,
                'overtime_pending_minutes' => (int) $rows[$e->id]->ot_pending,
                'overtime_approved_minutes' => (int) $rows[$e->id]->ot_approved,
            ])->values()->all();
    }

    private function decideOvertime(HrAttendance $row, string $status, ?int $minutes, User $by, ?string $note): HrAttendance
    {
        Validator::make(['minutes' => $minutes, 'note' => $note, 'status' => $status], [
            'minutes' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => [Rule::in([HrAttendance::OVERTIME_APPROVED, HrAttendance::OVERTIME_REJECTED])],
        ])->validate();

        return DB::connection('tenant')->transaction(static function () use ($row, $status, $minutes, $by, $note): HrAttendance {
            /** @var HrAttendance $locked */
            $locked = HrAttendance::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($locked->overtime_status !== HrAttendance::OVERTIME_PENDING) {
                throw ApiException::invalidTransition($locked->overtime_status, $status);
            }

            if ($minutes !== null && $minutes > $locked->overtime_minutes) {
                throw ApiException::unprocessable('overtime_exceeds_recorded', 'At most '.$locked->overtime_minutes.' minutes of overtime were recorded.');
            }

            $locked->forceFill([
                'overtime_status' => $status,
                'overtime_approved_minutes' => $status === HrAttendance::OVERTIME_APPROVED ? ($minutes ?? $locked->overtime_minutes) : null,
                'overtime_decided_by_user_id' => $by->id,
                'overtime_decided_at' => now(),
                'overtime_note' => $note,
            ])->save();

            return $locked;
        });
    }

    /**
     * The expected start and end on the work date, and the unpaid break:
     * the shift's when rostered, else the settings' fixed hours (an end at
     * or before the start ends the next day).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    private function window(?HrShift $shift, HrSettings $rules, string $workDate, CarbonImmutable $now): array
    {
        $timezone = $now->getTimezone()->getName();

        if ($shift !== null) {
            [$start, $end] = $shift->window($workDate, $timezone);

            return [$start, $end, $shift->break_minutes];
        }

        $start = CarbonImmutable::parse($workDate.' '.$rules->expected_clock_in_time, $timezone);
        $end = CarbonImmutable::parse($workDate.' '.$rules->expected_clock_out_time, $timezone);

        return [$start, $end->lessThanOrEqualTo($start) ? $end->addDay() : $end, 0];
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) ($this->tenantSettings->get('timezone') ?: 'UTC'));
    }

    private function assertActive(HrEmployee $employee): void
    {
        if ($employee->status !== HrEmployee::ACTIVE) {
            throw ApiException::unprocessable('employee_inactive', 'This employee is not active.');
        }
    }
}
