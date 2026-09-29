<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrCandidate;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrJobApplication;
use App\Modules\Hr\Models\HrJobPosting;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Applications and the hiring pipeline (spec §58.7). Notes are internal
 * and never leave the admin routes.
 */
final readonly class HrJobApplicationService
{
    public function __construct(private HrEmployeeService $employees) {}

    /**
     * Open postings before their deadline only; one application per
     * candidate per posting.
     *
     * @param  array<string, mixed>  $data  cover_letter?
     */
    public function apply(HrJobPosting $posting, HrCandidate $candidate, array $data): HrJobApplication
    {
        $validated = Validator::make($data, ['cover_letter' => ['sometimes', 'nullable', 'string', 'max:10000']])->validate();

        if (! $posting->acceptsApplications()) {
            throw ApiException::unprocessable('job_posting_not_open', 'This job is no longer accepting applications.');
        }

        try {
            $application = new HrJobApplication;
            $application->forceFill([
                'job_posting_id' => $posting->id,
                'candidate_id' => $candidate->id,
                'cover_letter' => $validated['cover_letter'] ?? null,
                'status' => HrJobApplication::APPLIED,
                'applied_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::unprocessable('already_applied', 'You have already applied for this job.');
        }

        return $application;
    }

    /**
     * Any stage to any other; hired once converted is final.
     */
    public function updateStatus(HrJobApplication $application, string $status): HrJobApplication
    {
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(HrJobApplication::STATUSES)]])->validate();

        if ($application->converted_employee_id !== null) {
            throw ApiException::unprocessable('application_converted', 'This applicant has already been hired as an employee.');
        }

        $application->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

        return $application;
    }

    /**
     * Appended with a timestamp, so earlier notes are kept.
     */
    public function addNote(HrJobApplication $application, string $note, string $author): HrJobApplication
    {
        Validator::make(['note' => $note], ['note' => ['required', 'string', 'max:5000']])->validate();

        return DB::connection('tenant')->transaction(static function () use ($application, $note, $author): HrJobApplication {
            /** @var HrJobApplication $locked */
            $locked = HrJobApplication::query()->lockForUpdate()->findOrFail($application->id);
            $entry = '['.now()->toDateTimeString().' '.$author.'] '.$note;
            $locked->forceFill(['notes' => $locked->notes === null ? $entry : $locked->notes."\n\n".$entry])->save();

            return $locked;
        });
    }

    /**
     * @param  array{status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, HrJobApplication>
     */
    public function listApplicationsForPosting(HrJobPosting $posting, array $filters = []): LengthAwarePaginator
    {
        return HrJobApplication::query()->with('candidate.media')->where('job_posting_id', $posting->id)
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('applied_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * A standalone employee from the candidate's name and contact details
     * plus the given employee data; hired applications only, once.
     *
     * @param  array<string, mixed>  $employeeData  employment_type, hire_date, department_id?, job_title?, employee_number?
     */
    public function convertToEmployee(HrJobApplication $application, array $employeeData): HrEmployee
    {
        return DB::connection('tenant')->transaction(function () use ($application, $employeeData): HrEmployee {
            /** @var HrJobApplication $locked */
            $locked = HrJobApplication::query()->with(['candidate', 'posting'])->lockForUpdate()->findOrFail($application->id);

            if ($locked->status !== HrJobApplication::HIRED) {
                throw ApiException::unprocessable('application_not_hired', 'Mark the application hired first.');
            }

            if ($locked->converted_employee_id !== null) {
                throw ApiException::unprocessable('application_converted', 'This applicant is already an employee.');
            }

            $candidate = $locked->candidate;
            $employee = $this->employees->createEmployee([
                'department_id' => $locked->posting->department_id,
                'job_title' => $locked->posting->title,
                'employment_type' => $locked->posting->employment_type,
                ...array_intersect_key($employeeData, array_flip(['employment_type', 'hire_date', 'department_id', 'job_title', 'employee_number', 'phone'])),
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'email' => $candidate->email,
                'phone' => $employeeData['phone'] ?? $candidate->phone,
            ]);

            $locked->forceFill(['converted_employee_id' => $employee->id])->save();

            return $employee;
        });
    }
}
