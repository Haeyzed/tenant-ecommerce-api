<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrLeaveType;
use App\Modules\Hr\Services\HrLeaveService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveTypeController extends Controller
{
    private const array FIELDS = ['name', 'days_per_year', 'is_paid', 'is_active'];

    public function __construct(
        private readonly HrLeaveService $leave,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->leave->listLeaveTypes()->map(fn (HrLeaveType $t): array => $this->presenter->leaveType($t))->all());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->leaveType($this->leave->createLeaveType($request->only(self::FIELDS))), 'Leave type created');
    }

    public function update(Request $request, HrLeaveType $leaveType): JsonResponse
    {
        return APIResponse::success($this->presenter->leaveType($this->leave->updateLeaveType($leaveType, $request->only(self::FIELDS))), 'Leave type updated');
    }
}
