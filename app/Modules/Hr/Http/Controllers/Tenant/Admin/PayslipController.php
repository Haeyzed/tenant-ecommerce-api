<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Services\HrPayrollService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

final class PayslipController extends Controller
{
    public function __construct(
        private readonly HrPayrollService $payroll,
        private readonly HrPresenter $presenter,
    ) {}

    public function show(HrEmployee $employee, HrPayrollRun $run): JsonResponse
    {
        return APIResponse::success($this->presenter->item($this->payroll->getPayslip($employee, $run)));
    }

    public function history(HrEmployee $employee): JsonResponse
    {
        return APIResponse::success($this->payroll->getPayrollHistory($employee)->map(fn (HrPayrollItem $i): array => $this->presenter->item($i))->all());
    }
}
