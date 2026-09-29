<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrSalaryStructure;
use App\Modules\Hr\Services\HrPayrollService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Salary structures (spec §58.5): the current one and the history.
 */
final class SalaryController extends Controller
{
    public function __construct(
        private readonly HrPayrollService $payroll,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(HrEmployee $employee): JsonResponse
    {
        $current = $this->payroll->getCurrentSalary($employee);

        return APIResponse::success([
            'current' => $current === null ? null : $this->presenter->salary($current),
            'history' => $this->payroll->getSalaryHistory($employee)->map(fn (HrSalaryStructure $s): array => $this->presenter->salary($s))->all(),
        ]);
    }

    /**
     * Body: base_salary, effective_from (after the current structure's start).
     */
    public function store(Request $request, HrEmployee $employee): JsonResponse
    {
        return APIResponse::created($this->presenter->salary($this->payroll->createSalaryStructure($employee, $request->only(['base_salary', 'effective_from']))), 'Salary saved');
    }
}
