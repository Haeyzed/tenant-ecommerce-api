<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Clock-in and clock-out (spec §58.3): one row per employee per day in the
 * tenant timezone; lateness and early leave against the applicable HR
 * settings (the department's row, else the tenant-wide row).
 */
final readonly class HrAttendanceService
{
    public function __construct(
        private HrSettingsService $settings,
        private TenantSettingsService $tenantSettings,
    ) {}

    public function clockIn(HrEmployee $employee, ?string $notes = null): HrAttendance
    {
        $this->assertActive($employee);
        $now = $this->now();
        $rules = $this->settings->getApplicableSettings($employee);
        $expected = CarbonImmutable::parse($now->toDateString().' '.$rules->expected_clock_in_time, $now->getTimezone());

        try {
            $row = new HrAttendance;
            $row->forceFill([
                'employee_id' => $employee->id,
                'work_date' => $now->toDateString(),
                'clock_in_at' => $now->utc(),
                'is_late' => $now->greaterThan($expected->addMinutes($rules->late_grace_minutes)),
                'notes' => $notes === null ? null : mb_substr($notes, 0, 255),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::unprocessable('already_clocked_in', 'This employee has already clocked in today.');
        }

        return $row;
    }

    /**
     * Closes today's open row.
     */
    public function clockOut(HrEmployee $employee): HrAttendance
    {
        $now = $this->now();

        return DB::connection('tenant')->transaction(function () use ($employee, $now): HrAttendance {
            /** @var HrAttendance|null $row */
            $row = HrAttendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $now->toDateString())->lockForUpdate()->first();

            if ($row === null) {
                throw ApiException::unprocessable('not_clocked_in', 'This employee has not clocked in today.');
            }

            if ($row->clock_out_at !== null) {
                throw ApiException::unprocessable('already_clocked_out', 'This employee has already clocked out today.');
            }

            $rules = $this->settings->getApplicableSettings($employee);
            $expected = CarbonImmutable::parse($now->toDateString().' '.$rules->expected_clock_out_time, $now->getTimezone());

            $row->forceFill([
                'clock_out_at' => $now->utc(),
                'is_early_leave' => $now->lessThan($expected->subMinutes($rules->early_leave_grace_minutes)),
            ])->save();

            return $row;
        });
    }

    /**
     * @return Collection<int, HrAttendance>
     */
    public function getAttendanceForEmployee(HrEmployee $employee, string $from, string $to): Collection
    {
        return HrAttendance::query()->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get();
    }

    /**
     * Per employee: days present, late arrivals and early departures in the range.
     *
     * @return list<array{employee_id: int, employee_name: string, days_present: int, late: int, early_leave: int}>
     */
    public function getAttendanceSummary(string $from, string $to): array
    {
        $rows = DB::connection('tenant')->table('hr_attendance')->whereBetween('work_date', [$from, $to])
            ->selectRaw('employee_id, COUNT(*) as days_present, SUM(is_late) as late, SUM(is_early_leave) as early_leave')
            ->groupBy('employee_id')->get()->keyBy('employee_id');

        return HrEmployee::query()->withTrashed()->with('user:id,name')->whereKey($rows->keys())->orderBy('id')->get()
            ->map(static fn (HrEmployee $e): array => [
                'employee_id' => $e->id,
                'employee_name' => $e->displayName(),
                'days_present' => (int) $rows[$e->id]->days_present,
                'late' => (int) $rows[$e->id]->late,
                'early_leave' => (int) $rows[$e->id]->early_leave,
            ])->values()->all();
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
