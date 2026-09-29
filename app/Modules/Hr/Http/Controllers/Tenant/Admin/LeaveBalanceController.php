<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrLeaveBalance;
use App\Modules\Hr\Services\HrLeaveService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly HrLeaveService $leave,
        private readonly HrPresenter $presenter,
    ) {}

    /**
     * Query: year? (default this year).
     */
    public function index(Request $request, HrEmployee $employee): JsonResponse
    {
        $year = $request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']])['year'] ?? null;

        return APIResponse::success($this->leave->listBalances($employee, $year === null ? null : (int) $year)
            ->map(fn (HrLeaveBalance $b): array => $this->presenter->leaveBalance($b))->all());
    }
}
