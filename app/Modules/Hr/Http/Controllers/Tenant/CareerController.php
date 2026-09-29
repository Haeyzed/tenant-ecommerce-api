<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrJobPosting;
use App\Modules\Hr\Services\HrCandidateService;
use App\Modules\Hr\Services\HrJobApplicationService;
use App\Modules\Hr\Services\HrJobPostingService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The public careers page (spec §58.7, §58.9). Applicants are not users;
 * nothing internal (status, notes) is returned.
 */
final class CareerController extends Controller
{
    public function __construct(
        private readonly HrJobPostingService $postings,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'department_id' => ['sometimes', 'integer'],
            'location' => ['sometimes', 'string', 'max:120'],
            'employment_type' => ['sometimes', Rule::in(HrEmployee::EMPLOYMENT_TYPES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return APIResponse::success($this->postings->listOpenPostings($filters)->through(fn (HrJobPosting $p): array => $this->presenter->posting($p, false)));
    }

    public function show(string $slug): JsonResponse
    {
        return APIResponse::success($this->presenter->posting($this->postings->getPostingBySlug($slug), false));
    }

    /**
     * Multipart: first_name, last_name, email, phone?, linkedin_url?, cover_letter?, resume (file).
     */
    public function apply(Request $request, string $slug, HrCandidateService $candidates, HrJobApplicationService $applications): JsonResponse
    {
        $posting = $this->postings->getPostingBySlug($slug);

        if (! $posting->acceptsApplications()) {
            throw ApiException::unprocessable('job_posting_not_open', 'This job is no longer accepting applications.');
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'linkedin_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'cover_letter' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'resume' => ['required', 'file'],
        ]);
        /** @var UploadedFile $resume */
        $resume = $validated['resume'];

        $candidate = $candidates->findOrCreateByEmail((string) $validated['email'], [
            ...array_intersect_key($validated, array_flip(['first_name', 'last_name', 'phone', 'linkedin_url'])),
            'source' => 'career page',
        ], $resume);
        $application = $applications->apply($posting, $candidate, ['cover_letter' => $validated['cover_letter'] ?? null]);

        return APIResponse::created([
            'id' => $application->id,
            'job' => ['title' => $posting->title, 'slug' => $posting->slug],
            'applied_at' => $application->applied_at->toIso8601String(),
        ], 'Application received');
    }
}
