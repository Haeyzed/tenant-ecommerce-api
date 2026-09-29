<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Services\HrAttendanceService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Overtime review (spec §58.3a): recorded at clock-out as pending; only
 * approved minutes reach payroll.
 */
final class OvertimeController extends Controller
{
    public function __construct(
        private readonly HrAttendanceService $attendance,
        private readonly HrPresenter $presenter,
    ) {}

    /**
     * Query: status? (pending, approved, rejected; default pending), employee_id?, from?, to?, per_page?
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([HrAttendance::OVERTIME_PENDING, HrAttendance::OVERTIME_APPROVED, HrAttendance::OVERTIME_REJECTED])],
            'employee_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->attendance->listOvertime($filters)->through(fn (HrAttendance $row): array => $this->presenter->attendance($row)));
    }

    /**
     * Body: minutes? (fewer than recorded; default all), note?
     */
    public function approve(Request $request, HrAttendance $attendance): JsonResponse
    {
        $validated = $request->validate(['minutes' => ['sometimes', 'nullable', 'integer', 'min:1'], 'note' => ['sometimes', 'nullable', 'string', 'max:255']]);
        /** @var User $user */
        $user = $request->user();
        $row = $this->attendance->approveOvertime($attendance, isset($validated['minutes']) ? (int) $validated['minutes'] : null, $user, $validated['note'] ?? null);

        return APIResponse::success($this->presenter->attendance($row->load('overtimeDecidedBy:id,name')), 'Overtime approved');
    }

    /**
     * Body: note?
     */
    public function reject(Request $request, HrAttendance $attendance): JsonResponse
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:255']])['note'] ?? null;
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->presenter->attendance($this->attendance->rejectOvertime($attendance, $user, $note)->load('overtimeDecidedBy:id,name')), 'Overtime rejected');
    }
}
