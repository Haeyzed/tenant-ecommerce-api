<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrDepartment;
use App\Modules\Hr\Models\HrEmployee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Departments (spec §58.2, §58.8).
 */
final readonly class HrDepartmentService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createDepartment(array $data): HrDepartment
    {
        $department = new HrDepartment;
        $department->forceFill($this->validate($data, true))->save();

        return $department;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDepartment(HrDepartment $department, array $data): HrDepartment
    {
        $department->forceFill($this->validate($data, false))->save();

        return $department;
    }

    public function assignEmployeeToDepartment(HrEmployee $employee, HrDepartment $department): HrEmployee
    {
        $employee->forceFill(['department_id' => $department->id])->save();

        return $employee;
    }

    /**
     * @param  array{is_active?: bool}  $filters
     * @return Collection<int, HrDepartment>
     */
    public function listDepartments(array $filters = []): Collection
    {
        return HrDepartment::query()->withCount(['employees' => static fn ($q) => $q->where('status', HrEmployee::ACTIVE)])
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
