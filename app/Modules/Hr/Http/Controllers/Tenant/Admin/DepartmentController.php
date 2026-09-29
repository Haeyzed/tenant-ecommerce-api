<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrDepartment;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Services\HrDepartmentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DepartmentController extends Controller
{
    public function __construct(
        private readonly HrDepartmentService $departments,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['is_active' => ['sometimes', 'boolean']]);

        return APIResponse::success($this->departments->listDepartments($filters)->map(fn (HrDepartment $d): array => $this->presenter->department($d))->all());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->department($this->departments->createDepartment($request->only(['name', 'description', 'is_active']))), 'Department created');
    }

    public function update(Request $request, HrDepartment $department): JsonResponse
    {
        return APIResponse::success($this->presenter->department($this->departments->updateDepartment($department, $request->only(['name', 'description', 'is_active']))), 'Department updated');
    }

    /**
     * Body: employee_id.
     */
    public function assignEmployee(Request $request, HrDepartment $department): JsonResponse
    {
        $employee = HrEmployee::query()->findOrFail((int) $request->validate(['employee_id' => ['required', 'integer']])['employee_id']);

        return APIResponse::success($this->presenter->employee($this->departments->assignEmployeeToDepartment($employee, $department)), 'Employee assigned');
    }
}
