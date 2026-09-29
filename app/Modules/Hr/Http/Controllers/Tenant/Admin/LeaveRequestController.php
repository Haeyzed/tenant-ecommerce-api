<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\Concerns\ResolvesActingEmployee;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Services\HrLeaveService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave requests (spec §58.4). Submitting and cancelling one's own request
 * are self-service; hr.leave-requests.create and hr.leave-requests.cancel
 * let staff do so for others.
 */
final class LeaveRequestController extends Controller
{
    use ResolvesActingEmployee;

    public const string CREATE = 'hr.leave-requests.create';

    public const string CANCEL = 'hr.leave-requests.cancel';

    public function __construct(
        private readonly HrLeaveService $leave,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(HrLeaveRequest::STATUSES)],
            'employee_id' => ['sometimes', 'integer'],
            'leave_type_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->leave->listRequests($filters)->through(fn (HrLeaveRequest $r): array => $this->presenter->leaveRequest($r)));
    }

    /**
     * Body: leave_type_id, start_date, end_date, days? (half days), reason?,
     * employee_id? (with hr.leave-requests.create).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $this->actingEmployee($request, self::CREATE);

        return APIResponse::created($this->presenter->leaveRequest($this->leave->submitRequest($employee, $request->only(['leave_type_id', 'start_date', 'end_date', 'days', 'reason']), $user)), 'Leave requested');
    }

    public function approve(Request $request, HrLeaveRequest $leaveRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->presenter->leaveRequest($this->leave->approveRequest($leaveRequest, $user)), 'Leave approved');
    }

    /**
     * Body: reason.
     */
    public function reject(Request $request, HrLeaveRequest $leaveRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->presenter->leaveRequest($this->leave->rejectRequest($leaveRequest, (string) $request->input('reason', ''), $user)), 'Leave rejected');
    }

    public function cancel(Request $request, HrLeaveRequest $leaveRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $leaveRequest->loadMissing('employee');

        if (! $this->isOwn($request, $leaveRequest->employee) && ! $user->hasPermissionTo(self::CANCEL, 'staff')) {
            throw ApiException::forbidden('forbidden', 'You may only cancel your own leave requests.', ['permission' => self::CANCEL]);
        }

        return APIResponse::success($this->presenter->leaveRequest($this->leave->cancelRequest($leaveRequest)), 'Leave request cancelled');
    }
}
