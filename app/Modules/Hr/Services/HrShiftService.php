<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrShift;
use App\Modules\Hr\Models\HrShiftAssignment;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Shift templates and the roster (spec §58.3a). A roster entry gives an
 * employee a shift on a work date; attendance on that date is measured
 * against it instead of the HR settings' fixed hours.
 */
final readonly class HrShiftService
{
    /** The largest roster change in one call: employees × days. */
    public const int MAX_ASSIGNMENTS = 5000;

    /**
     * @return Collection<int, HrShift>
     */
    public function listShifts(bool $activeOnly = false): Collection
    {
        return HrShift::query()->when($activeOnly, static fn ($q) => $q->where('is_active', true))->orderBy('start_time')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data  name, start_time, end_time (HH:MM), break_minutes?, late_grace_minutes?, early_leave_grace_minutes?, color?
     */
    public function createShift(array $data): HrShift
    {
        $shift = new HrShift;
        $shift->forceFill(['break_minutes' => 0, 'late_grace_minutes' => null, 'early_leave_grace_minutes' => null, 'color' => null, 'is_active' => true]);
        $shift->forceFill($this->validate($data, $shift, true))->save();

        return $shift;
    }

    /**
     * @param  array<string, mixed>  $data  any createShift field, is_active?
     */
    public function updateShift(HrShift $shift, array $data): HrShift
    {
        $shift->forceFill($this->validate($data, $shift, false))->save();

        return $shift;
    }

    /**
     * A shift that was ever rostered or worked is kept for history; it can
     * only be deactivated.
     */
    public function deleteShift(HrShift $shift): void
    {
        if (HrShiftAssignment::query()->where('shift_id', $shift->id)->exists() || HrAttendance::query()->where('shift_id', $shift->id)->exists()) {
            throw ApiException::conflict('shift_in_use', 'This shift has been rostered; deactivate it instead.');
        }

        $shift->delete();
    }

    /**
     * Rosters the employees on the shift for every matching date in the
     * range. An existing entry on a date is replaced only with $replace;
     * otherwise that date is skipped. Returns the counts.
     *
     * @param  array<string, mixed>  $data  employee_ids, shift_id, from, to, weekdays? (1 = Monday … 7 = Sunday), replace?, notes?
     * @return array{assigned: int, replaced: int, skipped: int}
     */
    public function assign(array $data, ?User $by = null): array
    {
        $validated = Validator::make($data, [
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', 'distinct'],
            'shift_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'weekdays' => ['sometimes', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'replace' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        $shift = HrShift::query()->where('is_active', true)->find($validated['shift_id'])
            ?? throw ApiException::unprocessable('shift_unavailable', 'Choose an active shift.');
        $employees = HrEmployee::query()->where('status', HrEmployee::ACTIVE)->whereKey($validated['employee_ids'])->pluck('id')->all();

        if (count($employees) !== count($validated['employee_ids'])) {
            throw ApiException::unprocessable('employee_inactive', 'Every employee must exist and be active.');
        }

        $weekdays = $validated['weekdays'] ?? [1, 2, 3, 4, 5, 6, 7];
        $dates = [];

        foreach (CarbonPeriod::create($validated['from'], $validated['to']) as $day) {
            if (in_array($day->isoWeekday(), $weekdays, true)) {
                $dates[] = $day->toDateString();
            }
        }

        if (count($dates) * count($employees) > self::MAX_ASSIGNMENTS) {
            throw ApiException::unprocessable('roster_too_large', 'Roster at most '.self::MAX_ASSIGNMENTS.' shifts at once; use a shorter range or fewer employees.');
        }

        $replace = (bool) ($validated['replace'] ?? false);
        $counts = ['assigned' => 0, 'replaced' => 0, 'skipped' => 0];

        DB::connection('tenant')->transaction(function () use ($employees, $dates, $shift, $replace, $validated, $by, &$counts): void {
            $existing = HrShiftAssignment::query()->whereIn('employee_id', $employees)->whereIn('work_date', $dates)->lockForUpdate()->get()
                ->keyBy(static fn (HrShiftAssignment $a): string => $a->employee_id.'|'.$a->work_date->toDateString());

            foreach ($employees as $employeeId) {
                foreach ($dates as $date) {
                    $row = $existing->get($employeeId.'|'.$date);

                    if ($row !== null && ! $replace) {
                        $counts['skipped']++;

                        continue;
                    }

                    $row ??= new HrShiftAssignment;
                    $counts[$row->exists ? 'replaced' : 'assigned']++;
                    $row->forceFill([
                        'employee_id' => $employeeId,
                        'shift_id' => $shift->id,
                        'work_date' => $date,
                        'notes' => $validated['notes'] ?? null,
                        'assigned_by_user_id' => $by?->id,
                    ])->save();
                }
            }
        });

        return $counts;
    }

    /**
     * Removes roster entries in the range; attendance already recorded keeps
     * the shift it was measured against.
     *
     * @param  array<string, mixed>  $data  employee_ids, from, to
     */
    public function unassign(array $data): int
    {
        $validated = Validator::make($data, [
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();

        return HrShiftAssignment::query()->whereIn('employee_id', $validated['employee_ids'])
            ->whereBetween('work_date', [$validated['from'], $validated['to']])->delete();
    }

    /**
     * @param  array{from: string, to: string, employee_id?: int|null, department_id?: int|null, shift_id?: int|null}  $filters
     * @return Collection<int, HrShiftAssignment>
     */
    public function roster(array $filters): Collection
    {
        return HrShiftAssignment::query()->with(['shift', 'employee.user:id,name'])
            ->whereBetween('work_date', [$filters['from'], $filters['to']])
            ->when($filters['employee_id'] ?? null, static fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['shift_id'] ?? null, static fn ($q, $v) => $q->where('shift_id', $v))
            ->when($filters['department_id'] ?? null, static fn ($q, $v) => $q->whereHas('employee', static fn ($e) => $e->where('department_id', $v)))
            ->orderBy('work_date')->orderBy('employee_id')->limit(self::MAX_ASSIGNMENTS)->get();
    }

    /**
     * The roster entry a clock-in at $now belongs to: today's, or
     * yesterday's night shift that is still running.
     */
    public function assignmentAt(HrEmployee $employee, CarbonImmutable $now): ?HrShiftAssignment
    {
        $today = $now->toDateString();
        $yesterday = $now->subDay()->toDateString();

        $rows = HrShiftAssignment::query()->with('shift')->where('employee_id', $employee->id)
            ->whereIn('work_date', [$today, $yesterday])->get()->keyBy(static fn (HrShiftAssignment $a): string => $a->work_date->toDateString());

        /** @var HrShiftAssignment|null $previous */
        $previous = $rows->get($yesterday);

        if ($previous !== null && $previous->shift->isOvernight() && $now->lessThan($previous->shift->window($yesterday, $now->getTimezone()->getName())[1])) {
            // Unless today's own shift has already started.
            $current = $rows->get($today);

            if ($current === null || $now->lessThan($current->shift->window($today, $now->getTimezone()->getName())[0]->subHours(4))) {
                return $previous;
            }
        }

        return $rows->get($today);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, HrShift $shift, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $validated = Validator::make($data, [
            'name' => [$required, 'string', 'max:80'],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],
            'late_grace_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:720'],
            'early_leave_grace_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:720'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        foreach (['start_time', 'end_time'] as $key) {
            if (isset($validated[$key])) {
                $validated[$key] .= ':00';
            }
        }

        $check = (clone $shift)->forceFill($validated);

        if ($check->start_time === $check->end_time) {
            throw ApiException::unprocessable('shift_times_invalid', 'A shift must start and end at different times.');
        }

        if ($check->scheduledMinutes() === 0) {
            throw ApiException::unprocessable('shift_break_too_long', 'The break must be shorter than the shift.');
        }

        return $validated;
    }
}
