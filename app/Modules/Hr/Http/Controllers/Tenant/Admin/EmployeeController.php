<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Services\HrEmployeeService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employees (spec §58.2, §58.9).
 */
final class EmployeeController extends Controller
{
    private const array FIELDS = ['user_id', 'department_id', 'employee_number', 'first_name', 'last_name', 'email', 'phone', 'job_title', 'employment_type', 'hire_date', 'custom_fields'];

    public function __construct(
        private readonly HrEmployeeService $employees,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([HrEmployee::ACTIVE, HrEmployee::INACTIVE])],
            'department_id' => ['sometimes', 'integer'],
            'employment_type' => ['sometimes', Rule::in(HrEmployee::EMPLOYMENT_TYPES)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->employees->listEmployees($filters)->through(fn (HrEmployee $e): array => $this->presenter->employee($e)));
    }

    /**
     * Body: user_id (a linked staff user) or first_name and last_name
     * (standalone), employment_type, hire_date, department_id?, employee_number?,
     * email? (standalone), phone?, job_title?, custom_fields?
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->employee($this->employees->createEmployee($request->only(self::FIELDS)), true), 'Employee created');
    }

    public function show(HrEmployee $employee): JsonResponse
    {
        return APIResponse::success($this->presenter->employee($employee, true));
    }

    public function update(Request $request, HrEmployee $employee): JsonResponse
    {
        return APIResponse::success($this->presenter->employee($this->employees->updateEmployee($employee, $request->only(self::FIELDS)), true), 'Employee updated');
    }

    public function destroy(HrEmployee $employee): JsonResponse
    {
        $this->employees->deleteEmployee($employee);

        return APIResponse::success(null, 'Employee deleted');
    }

    /**
     * Body: termination_date? (default today).
     */
    public function deactivate(Request $request, HrEmployee $employee): JsonResponse
    {
        $date = $request->input('termination_date');

        return APIResponse::success($this->presenter->employee($this->employees->deactivateEmployee($employee, is_string($date) ? $date : null)), 'Employee deactivated');
    }

    public function reactivate(HrEmployee $employee): JsonResponse
    {
        return APIResponse::success($this->presenter->employee($this->employees->reactivateEmployee($employee)), 'Employee reactivated');
    }

    /**
     * Body: user_id.
     */
    public function linkUser(Request $request, HrEmployee $employee): JsonResponse
    {
        $user = User::query()->findOrFail((int) $request->validate(['user_id' => ['required', 'integer']])['user_id']);

        return APIResponse::success($this->presenter->employee($this->employees->linkToUser($employee, $user)), 'User linked');
    }

    public function unlinkUser(HrEmployee $employee): JsonResponse
    {
        return APIResponse::success($this->presenter->employee($this->employees->unlinkFromUser($employee)), 'User unlinked');
    }
}
