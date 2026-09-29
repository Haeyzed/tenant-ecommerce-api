<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Hr\Models\HrAppraisal;
use App\Modules\Hr\Models\HrAppraisalScore;
use App\Modules\Hr\Models\HrAppraisalTemplate;
use App\Modules\Hr\Models\HrAppraisalTemplateCriterion;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrCandidate;
use App\Modules\Hr\Models\HrDepartment;
use App\Modules\Hr\Models\HrDocumentType;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrEmployeeDocument;
use App\Modules\Hr\Models\HrJobApplication;
use App\Modules\Hr\Models\HrJobPosting;
use App\Modules\Hr\Models\HrLeaveBalance;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Models\HrLeaveType;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollItemLine;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Models\HrSalaryStructure;
use App\Modules\Hr\Models\HrSettings;
use App\Modules\Hr\Services\HrEmployeeService;

/**
 * HR payloads (spec §58). Application notes are internal: the public
 * posting and application payloads never carry them.
 */
final readonly class HrPresenter
{
    public function __construct(private CustomFieldService $customFields) {}

    /**
     * @return array<string, mixed>
     */
    public function department(HrDepartment $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'description' => $department->description,
            'is_active' => $department->is_active,
            'active_employees' => $department->getAttributes()['employees_count'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function employee(HrEmployee $employee, bool $detail = false): array
    {
        $employee->loadMissing(['user:id,name,email', 'department:id,name']);
        $payload = [
            'id' => $employee->id,
            'name' => $employee->displayName(),
            'email' => $employee->contactEmail(),
            'user' => $employee->user === null ? null : ['id' => $employee->user->id, 'name' => $employee->user->name, 'email' => $employee->user->email],
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'employee_number' => $employee->employee_number,
            'department' => $employee->department === null ? null : ['id' => $employee->department->id, 'name' => $employee->department->name],
            'job_title' => $employee->job_title,
            'phone' => $employee->phone,
            'employment_type' => $employee->employment_type,
            'hire_date' => $employee->hire_date->toDateString(),
            'termination_date' => $employee->termination_date?->toDateString(),
            'status' => $employee->status,
        ];

        if ($detail) {
            $payload['custom_fields'] = $this->customFields->valuesFor($employee, HrEmployeeService::ENTITY, CustomFieldService::ADMIN);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function documentType(HrDocumentType $type): array
    {
        return $type->only(['id', 'name', 'requires_expiry_date', 'is_mandatory_at_onboarding', 'is_active']);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(HrEmployeeDocument $document): array
    {
        $file = $document->getFirstMedia('file');

        return [
            'id' => $document->id,
            'employee_id' => $document->employee_id,
            'employee_name' => $document->relationLoaded('employee') ? $document->employee->displayName() : null,
            'document_type' => $document->relationLoaded('documentType') ? ['id' => $document->documentType->id, 'name' => $document->documentType->name] : ['id' => $document->document_type_id],
            'expiry_date' => $document->expiry_date?->toDateString(),
            'is_expired' => $document->expiry_date !== null && $document->expiry_date->isBefore(today()),
            'notes' => $document->notes,
            'file' => $file === null ? null : ['name' => $file->name, 'size' => $file->size, 'mime_type' => $file->mime_type],
            'created_at' => $document->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(HrSettings $settings): array
    {
        return [
            'department_id' => $settings->department_id,
            'expected_clock_in_time' => substr($settings->expected_clock_in_time, 0, 5),
            'expected_clock_out_time' => substr($settings->expected_clock_out_time, 0, 5),
            'late_grace_minutes' => $settings->late_grace_minutes,
            'early_leave_grace_minutes' => $settings->early_leave_grace_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attendance(HrAttendance $row): array
    {
        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'work_date' => $row->work_date->toDateString(),
            'clock_in_at' => $row->clock_in_at->toIso8601String(),
            'clock_out_at' => $row->clock_out_at?->toIso8601String(),
            'is_late' => $row->is_late,
            'is_early_leave' => $row->is_early_leave,
            'notes' => $row->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function leaveType(HrLeaveType $type): array
    {
        return ['id' => $type->id, 'name' => $type->name, 'days_per_year' => (string) $type->days_per_year, 'is_paid' => $type->is_paid, 'is_active' => $type->is_active];
    }

    /**
     * @return array<string, mixed>
     */
    public function leaveBalance(HrLeaveBalance $balance): array
    {
        return [
            'leave_type' => ['id' => $balance->leave_type_id, 'name' => $balance->leaveType->name, 'is_paid' => $balance->leaveType->is_paid],
            'year' => $balance->year,
            'entitled_days' => (string) $balance->entitled_days,
            'used_days' => (string) $balance->used_days,
            'remaining_days' => $balance->remaining(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function leaveRequest(HrLeaveRequest $request): array
    {
        $request->loadMissing(['employee.user:id,name', 'leaveType:id,name', 'decidedBy:id,name']);

        return [
            'id' => $request->id,
            'employee' => ['id' => $request->employee_id, 'name' => $request->employee->displayName()],
            'leave_type' => ['id' => $request->leave_type_id, 'name' => $request->leaveType->name],
            'start_date' => $request->start_date->toDateString(),
            'end_date' => $request->end_date->toDateString(),
            'days' => (string) $request->days,
            'reason' => $request->reason,
            'status' => $request->status,
            'rejection_reason' => $request->rejection_reason,
            'decided_by' => $request->decidedBy === null ? null : ['id' => $request->decidedBy->id, 'name' => $request->decidedBy->name],
            'decided_at' => $request->decided_at?->toIso8601String(),
            'created_at' => $request->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salary(HrSalaryStructure $structure): array
    {
        return [
            'id' => $structure->id,
            'base_salary' => (string) $structure->base_salary,
            'currency_code' => $structure->currency_code,
            'effective_from' => $structure->effective_from->toDateString(),
            'effective_to' => $structure->effective_to?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(HrPayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'status' => $run->status,
            'run_date' => $run->run_date->toDateString(),
            'total_gross' => (string) $run->total_gross,
            'total_deductions' => (string) $run->total_deductions,
            'total_net' => (string) $run->total_net,
            'items_count' => $run->getAttributes()['items_count'] ?? ($run->relationLoaded('items') ? $run->items->count() : null),
            'finalized_at' => $run->finalized_at?->toIso8601String(),
            'paid_at' => $run->paid_at?->toIso8601String(),
        ];
    }

    /**
     * The payslip.
     *
     * @return array<string, mixed>
     */
    public function item(HrPayrollItem $item): array
    {
        $item->loadMissing(['employee.user:id,name', 'lines']);

        return [
            'id' => $item->id,
            'payroll_run_id' => $item->payroll_run_id,
            'period' => $item->relationLoaded('run') ? ['start' => $item->run->period_start->toDateString(), 'end' => $item->run->period_end->toDateString(), 'status' => $item->run->status] : null,
            'employee' => ['id' => $item->employee_id, 'name' => $item->employee->displayName(), 'employee_number' => $item->employee->employee_number],
            'base_salary' => (string) $item->base_salary,
            'total_allowances' => (string) $item->total_allowances,
            'gross_pay' => (string) $item->gross_pay,
            'total_deductions' => (string) $item->total_deductions,
            'tax_amount' => (string) $item->tax_amount,
            'net_pay' => (string) $item->net_pay,
            'status' => $item->status,
            'paid_at' => $item->paid_at?->toIso8601String(),
            'lines' => $item->lines->map(fn (HrPayrollItemLine $line): array => $this->line($line))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function line(HrPayrollItemLine $line): array
    {
        return [
            'id' => $line->id,
            'type' => $line->type,
            'label' => $line->label,
            'amount' => (string) $line->amount,
            'is_percentage' => $line->is_percentage,
            'percentage' => $line->percentage === null ? null : (string) $line->percentage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function template(HrAppraisalTemplate $template): array
    {
        $template->loadMissing('criteria');

        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'is_active' => $template->is_active,
            'total_weight' => $template->totalWeight(),
            'criteria' => $template->criteria->map(static fn (HrAppraisalTemplateCriterion $c): array => [
                'id' => $c->id, 'label' => $c->label, 'weight' => (string) $c->weight, 'max_score' => (string) $c->max_score, 'sort_order' => $c->sort_order,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function appraisal(HrAppraisal $appraisal): array
    {
        $appraisal->loadMissing(['template.criteria', 'reviewer:id,name', 'scores', 'employee.user:id,name']);
        $scores = $appraisal->scores->keyBy('appraisal_template_criterion_id');

        return [
            'id' => $appraisal->id,
            'employee' => ['id' => $appraisal->employee_id, 'name' => $appraisal->employee->displayName()],
            'template' => ['id' => $appraisal->appraisal_template_id, 'name' => $appraisal->template->name],
            'reviewer' => ['id' => $appraisal->reviewer_user_id, 'name' => $appraisal->reviewer->name],
            'period_start' => $appraisal->period_start->toDateString(),
            'period_end' => $appraisal->period_end->toDateString(),
            'status' => $appraisal->status,
            'overall_score' => $appraisal->overall_score === null ? null : (string) $appraisal->overall_score,
            'overall_comments' => $appraisal->overall_comments,
            'criteria' => $appraisal->template->criteria->map(static function (HrAppraisalTemplateCriterion $c) use ($scores): array {
                /** @var HrAppraisalScore|null $score */
                $score = $scores->get($c->id);

                return ['criterion_id' => $c->id, 'label' => $c->label, 'weight' => (string) $c->weight, 'max_score' => (string) $c->max_score,
                    'score' => $score === null ? null : (string) $score->score, 'comments' => $score?->comments];
            })->all(),
            'submitted_at' => $appraisal->submitted_at?->toIso8601String(),
            'acknowledged_at' => $appraisal->acknowledged_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function posting(HrJobPosting $posting, bool $admin): array
    {
        $posting->loadMissing('department:id,name');
        $payload = [
            'id' => $posting->id,
            'title' => $posting->title,
            'slug' => $posting->slug,
            'department' => $posting->department === null ? null : ['id' => $posting->department->id, 'name' => $posting->department->name],
            'description' => $posting->description,
            'requirements' => $posting->requirements,
            'location' => $posting->location,
            'employment_type' => $posting->employment_type,
            'application_deadline' => $posting->application_deadline?->toDateString(),
            'posted_at' => $posting->posted_at?->toIso8601String(),
        ];

        if ($admin) {
            $payload['status'] = $posting->status;
            $payload['closed_at'] = $posting->closed_at?->toIso8601String();
            $payload['applications_count'] = $posting->getAttributes()['applications_count'] ?? null;
        }

        return $payload;
    }

    /**
     * Staff only: includes the internal notes.
     *
     * @return array<string, mixed>
     */
    public function application(HrJobApplication $application): array
    {
        $application->loadMissing('candidate');

        return [
            'id' => $application->id,
            'job_posting_id' => $application->job_posting_id,
            'candidate' => $this->candidate($application->candidate),
            'cover_letter' => $application->cover_letter,
            'status' => $application->status,
            'status_changed_at' => $application->status_changed_at?->toIso8601String(),
            'notes' => $application->notes,
            'applied_at' => $application->applied_at->toIso8601String(),
            'converted_employee_id' => $application->converted_employee_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function candidate(HrCandidate $candidate): array
    {
        $resume = $candidate->getFirstMedia('resume');

        return [
            'id' => $candidate->id,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'linkedin_url' => $candidate->linkedin_url,
            'source' => $candidate->source,
            'resume' => $resume === null ? null : ['name' => $resume->name, 'size' => $resume->size, 'mime_type' => $resume->mime_type],
        ];
    }
}
