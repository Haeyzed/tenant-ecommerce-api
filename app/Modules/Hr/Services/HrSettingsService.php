<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrDepartment;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrSettings;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

/**
 * HR's own settings (spec §58.3): the tenant-wide row, created on first
 * access with 09:00–17:00 and no grace, and per-department overrides.
 */
final readonly class HrSettingsService
{
    public function getDefaultSettings(): HrSettings
    {
        $row = HrSettings::query()->whereNull('department_id')->first();

        if ($row !== null) {
            return $row;
        }

        try {
            $row = new HrSettings;
            $row->forceFill(['department_id' => null, 'expected_clock_in_time' => '09:00:00', 'expected_clock_out_time' => '17:00:00',
                'late_grace_minutes' => 0, 'early_leave_grace_minutes' => 0])->save();

            return $row;
        } catch (UniqueConstraintViolationException) {
            // Created concurrently.
            return HrSettings::query()->whereNull('department_id')->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setDefaultSettings(array $data): HrSettings
    {
        $row = $this->getDefaultSettings();
        $row->forceFill($this->validate($data, false))->save();

        return $row;
    }

    public function getDepartmentSettings(HrDepartment $department): ?HrSettings
    {
        return HrSettings::query()->where('department_id', $department->id)->first();
    }

    /**
     * Creates the department's own row, starting from the tenant-wide values.
     *
     * @param  array<string, mixed>  $data
     */
    public function setDepartmentSettings(HrDepartment $department, array $data): HrSettings
    {
        $validated = $this->validate($data, false);
        $row = $this->getDepartmentSettings($department);

        if ($row === null) {
            $defaults = $this->getDefaultSettings();
            $row = new HrSettings;
            $row->forceFill([
                'department_id' => $department->id,
                'expected_clock_in_time' => $defaults->expected_clock_in_time,
                'expected_clock_out_time' => $defaults->expected_clock_out_time,
                'late_grace_minutes' => $defaults->late_grace_minutes,
                'early_leave_grace_minutes' => $defaults->early_leave_grace_minutes,
            ]);
        }

        $row->forceFill($validated)->save();

        return $row;
    }

    /**
     * The department falls back to the tenant-wide row.
     */
    public function deleteDepartmentSettings(HrDepartment $department): void
    {
        $row = $this->getDepartmentSettings($department) ?? throw ApiException::unprocessable('settings_not_found', 'This department uses the default settings.');
        $row->delete();
    }

    public function getApplicableSettings(HrEmployee $employee): HrSettings
    {
        return ($employee->department_id === null ? null : HrSettings::query()->where('department_id', $employee->department_id)->first())
            ?? $this->getDefaultSettings();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $validated = Validator::make($data, [
            'expected_clock_in_time' => [$creating ? 'required' : 'sometimes', 'date_format:H:i'],
            'expected_clock_out_time' => [$creating ? 'required' : 'sometimes', 'date_format:H:i'],
            'late_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'early_leave_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:720'],
        ])->validate();

        foreach (['expected_clock_in_time', 'expected_clock_out_time'] as $key) {
            if (isset($validated[$key])) {
                $validated[$key] .= ':00';
            }
        }

        return $validated;
    }
}
