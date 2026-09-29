<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrJobApplication;
use App\Modules\Hr\Models\HrJobPosting;
use App\Modules\Hr\Services\HrJobApplicationService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use App\Shared\Media\PrivateFileResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hiring pipeline (spec §58.7). The résumé download is an addition:
 * the file is never served from a public URL.
 */
final class JobApplicationController extends Controller
{
    public function __construct(
        private readonly HrJobApplicationService $applications,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(Request $request, HrJobPosting $posting): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(HrJobApplication::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->applications->listApplicationsForPosting($posting, $filters)->through(fn (HrJobApplication $a): array => $this->presenter->application($a)));
    }

    /**
     * Body: status.
     */
    public function updateStatus(Request $request, HrJobApplication $application): JsonResponse
    {
        return APIResponse::success($this->presenter->application($this->applications->updateStatus($application, (string) $request->input('status', ''))), 'Status updated');
    }

    /**
     * Body: note.
     */
    public function addNote(Request $request, HrJobApplication $application): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->presenter->application($this->applications->addNote($application, (string) $request->input('note', ''), $user->name)), 'Note added');
    }

    public function resume(HrJobApplication $application, PrivateFileResponder $files): Response
    {
        return $files->respond($application->candidate->getFirstMedia('resume'));
    }

    /**
     * Body: employment_type?, hire_date, department_id?, job_title?, employee_number?, phone?
     */
    public function convertToEmployee(Request $request, HrJobApplication $application): JsonResponse
    {
        $employee = $this->applications->convertToEmployee($application, $request->only(['employment_type', 'hire_date', 'department_id', 'job_title', 'employee_number', 'phone']));

        return APIResponse::created($this->presenter->employee($employee, true), 'Employee created from the application');
    }
}
