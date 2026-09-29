<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Contracts\Approvable;
use App\Modules\Approvals\Support\ApprovalGate;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrLeaveBalance;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Models\HrLeaveType;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Leave types, balances and requests (spec §58.4). A request is checked
 * against the remaining balance less other pending requests; approval
 * books the days. A matching leave_request workflow (§60) decides instead
 * of staff.
 */
final readonly class HrLeaveService implements Approvable
{
    public function __construct(
        private ApprovalGate $approvals,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createLeaveType(array $data): HrLeaveType
    {
        $type = new HrLeaveType;
        $type->forceFill($this->validateType($data, true))->save();

        return $type;
    }

    /**
     * Entitlements already created for a year keep their days.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateLeaveType(HrLeaveType $type, array $data): HrLeaveType
    {
        $type->forceFill($this->validateType($data, false))->save();

        return $type;
    }

    /**
     * @return Collection<int, HrLeaveType>
     */
    public function listLeaveTypes(bool $activeOnly = false): Collection
    {
        return HrLeaveType::query()->when($activeOnly, static fn ($q) => $q->where('is_active', true))->orderBy('name')->get();
    }

    /**
     * entitled − used for the year (Assumption A-49: created on first access).
     */
    public function getBalance(HrEmployee $employee, HrLeaveType $type, ?int $year = null): string
    {
        return $this->balanceRow($employee, $type, $year ?? (int) today()->year)->remaining();
    }

    /**
     * The year's balance of every active type.
     *
     * @return Collection<int, HrLeaveBalance>
     */
    public function listBalances(HrEmployee $employee, ?int $year = null): Collection
    {
        $year ??= (int) today()->year;

        foreach ($this->listLeaveTypes(true) as $type) {
            $this->balanceRow($employee, $type, $year);
        }

        return HrLeaveBalance::query()->with('leaveType:id,name,is_paid')->where('employee_id', $employee->id)->where('year', $year)->orderBy('leave_type_id')->get();
    }

    /**
     * @param  array<string, mixed>  $data  leave_type_id, start_date, end_date, days? (half days; default: the weekdays of the range), reason?
     */
    public function submitRequest(HrEmployee $employee, array $data, ?User $by = null): HrLeaveRequest
    {
        $validated = Validator::make($data, [
            'leave_type_id' => ['required', 'integer', Rule::exists('tenant.hr_leave_types', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'days' => ['sometimes', 'numeric', 'gt:0', 'multiple_of:0.5'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        if ($employee->status !== HrEmployee::ACTIVE) {
            throw ApiException::unprocessable('employee_inactive', 'This employee is not active.');
        }

        $start = CarbonImmutable::parse($validated['start_date']);
        $end = CarbonImmutable::parse($validated['end_date']);

        if ($start->year !== $end->year) {
            throw ApiException::unprocessable('leave_spans_years', 'Request leave for each year separately.');
        }

        $weekdays = collect(CarbonPeriod::create($start, $end))->filter(static fn ($d): bool => ! $d->isWeekend())->count();
        $days = isset($validated['days']) ? bcadd((string) $validated['days'], '0', 1) : bcadd((string) $weekdays, '0', 1);

        if (bccomp($days, '0', 1) <= 0 || bccomp($days, (string) ($start->diffInDays($end) + 1), 1) > 0) {
            throw ApiException::unprocessable('leave_days_invalid', 'The number of days does not fit the dates.');
        }

        /** @var HrLeaveType $type */
        $type = HrLeaveType::query()->findOrFail((int) $validated['leave_type_id']);

        $request = DB::connection('tenant')->transaction(function () use ($employee, $type, $start, $end, $days, $validated, $by): HrLeaveRequest {
            $balance = $this->balanceRow($employee, $type, $start->year, true);

            if (HrLeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', [HrLeaveRequest::PENDING, HrLeaveRequest::APPROVED])
                ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists()) {
                throw ApiException::unprocessable('leave_overlap', 'This employee already has leave on some of these days.');
            }

            if (bccomp($days, $this->available($balance), 1) > 0) {
                throw ApiException::unprocessable('insufficient_leave_balance', 'Not enough leave left for this request.', ['available' => $this->available($balance)]);
            }

            $request = new HrLeaveRequest;
            $request->forceFill([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days,
                'reason' => $validated['reason'] ?? null,
                'status' => HrLeaveRequest::PENDING,
                'requested_by_user_id' => $by?->id,
            ])->save();

            $this->approvals->hold('leave_request', $request, $by);

            return $request;
        });

        $this->notifications->dispatch('hr.leave_request_submitted', null, $this->variables($request));

        return $request;
    }

    public function approveRequest(HrLeaveRequest $request, ?User $by = null): HrLeaveRequest
    {
        $this->approvals->assertNoPending($request);

        return $this->approve($request, $by);
    }

    public function rejectRequest(HrLeaveRequest $request, string $reason, ?User $by = null): HrLeaveRequest
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        $this->approvals->assertNoPending($request);

        return $this->reject($request, $reason, $by);
    }

    /**
     * Pending only; an open approval request is withdrawn with it.
     */
    public function cancelRequest(HrLeaveRequest $request): HrLeaveRequest
    {
        return DB::connection('tenant')->transaction(function () use ($request): HrLeaveRequest {
            /** @var HrLeaveRequest $locked */
            $locked = HrLeaveRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== HrLeaveRequest::PENDING) {
                throw ApiException::invalidTransition($locked->status, HrLeaveRequest::CANCELLED);
            }

            $this->approvals->cancel($locked);
            $locked->forceFill(['status' => HrLeaveRequest::CANCELLED])->save();

            return $locked;
        });
    }

    /**
     * @param  array{status?: string, employee_id?: int, leave_type_id?: int, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, HrLeaveRequest>
     */
    public function listRequests(array $filters = []): LengthAwarePaginator
    {
        return HrLeaveRequest::query()->with(['employee.user:id,name', 'leaveType:id,name', 'decidedBy:id,name'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['employee_id']), static fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->when(isset($filters['leave_type_id']), static fn ($q) => $q->where('leave_type_id', $filters['leave_type_id']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('end_date', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('start_date', '<=', $filters['to']))
            ->orderByDesc('start_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    // ---- Approvals (§60, module_key leave_request) ---------------------

    public function onApprovalGranted(Model $record): void
    {
        /** @var HrLeaveRequest $record */
        $this->approve($record, null);
    }

    public function onApprovalRejected(Model $record, ?string $note): void
    {
        /** @var HrLeaveRequest $record */
        $this->reject($record, $note ?? 'The request was not approved.', null);
    }

    public function approvalSubject(Model $record): string
    {
        /** @var HrLeaveRequest $record */
        $record->loadMissing(['employee.user', 'leaveType']);

        return $record->leaveType->name.' leave for '.$record->employee->displayName();
    }

    public function approvalFacts(Model $record): array
    {
        /** @var HrLeaveRequest $record */
        return ['days' => (string) $record->days];
    }

    // ---- Internals ------------------------------------------------------

    private function approve(HrLeaveRequest $request, ?User $by): HrLeaveRequest
    {
        $approved = DB::connection('tenant')->transaction(function () use ($request, $by): HrLeaveRequest {
            /** @var HrLeaveRequest $locked */
            $locked = HrLeaveRequest::query()->with(['employee', 'leaveType'])->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== HrLeaveRequest::PENDING) {
                throw ApiException::invalidTransition($locked->status, HrLeaveRequest::APPROVED);
            }

            $balance = $this->balanceRow($locked->employee, $locked->leaveType, (int) $locked->start_date->year, true);

            if (bccomp((string) $locked->days, $balance->remaining(), 1) > 0) {
                throw ApiException::unprocessable('insufficient_leave_balance', 'Not enough leave left to approve this request.', ['available' => $balance->remaining()]);
            }

            $balance->forceFill(['used_days' => bcadd((string) $balance->used_days, (string) $locked->days, 1)])->save();
            $locked->forceFill(['status' => HrLeaveRequest::APPROVED, 'decided_by_user_id' => $by?->id, 'decided_at' => now()])->save();

            return $locked;
        });

        $this->notifyEmployee('hr.leave_request_approved', $approved, []);

        return $approved;
    }

    private function reject(HrLeaveRequest $request, string $reason, ?User $by): HrLeaveRequest
    {
        $rejected = DB::connection('tenant')->transaction(static function () use ($request, $reason, $by): HrLeaveRequest {
            /** @var HrLeaveRequest $locked */
            $locked = HrLeaveRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== HrLeaveRequest::PENDING) {
                throw ApiException::invalidTransition($locked->status, HrLeaveRequest::REJECTED);
            }

            $locked->forceFill(['status' => HrLeaveRequest::REJECTED, 'rejection_reason' => mb_substr($reason, 0, 255), 'decided_by_user_id' => $by?->id, 'decided_at' => now()])->save();

            return $locked;
        });

        $this->notifyEmployee('hr.leave_request_rejected', $rejected, ['reason' => (string) $rejected->rejection_reason]);

        return $rejected;
    }

    /**
     * Remaining days less other pending requests, so staff can't approve
     * past the entitlement.
     */
    private function available(HrLeaveBalance $balance): string
    {
        $pending = (string) HrLeaveRequest::query()->where('employee_id', $balance->employee_id)->where('leave_type_id', $balance->leave_type_id)
            ->where('status', HrLeaveRequest::PENDING)->whereYear('start_date', $balance->year)->sum('days');

        return bcsub($balance->remaining(), bcadd($pending, '0', 1), 1);
    }

    private function balanceRow(HrEmployee $employee, HrLeaveType $type, int $year, bool $lock = false): HrLeaveBalance
    {
        $find = static fn () => HrLeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year)
            ->when($lock, static fn ($q) => $q->lockForUpdate())->first();
        $row = $find();

        if ($row !== null) {
            return $row;
        }

        try {
            $row = new HrLeaveBalance;
            $row->forceFill(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year,
                'entitled_days' => (string) $type->days_per_year, 'used_days' => '0'])->save();

            return $lock ? $find() : $row;
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    /**
     * The employee's linked user only: a standalone employee has no staff inbox.
     *
     * @param  array<string, string>  $extra
     */
    private function notifyEmployee(string $key, HrLeaveRequest $request, array $extra): void
    {
        $request->loadMissing(['employee.user', 'leaveType']);
        $user = $request->employee->user;

        if ($user !== null && $user->is_active) {
            $this->notifications->dispatch($key, $user, [...$this->variables($request), ...$extra]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function variables(HrLeaveRequest $request): array
    {
        $request->loadMissing(['employee.user', 'leaveType']);

        return [
            'employee_name' => $request->employee->displayName(),
            'leave_type' => $request->leaveType->name,
            'starts_on' => $request->start_date->toDateString(),
            'ends_on' => $request->end_date->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateType(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'days_per_year' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:366', 'multiple_of:0.5'],
            'is_paid' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
