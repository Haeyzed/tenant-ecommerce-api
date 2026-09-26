<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\ContactSubmission;
use App\Modules\Cms\Services\ContactSubmissionService;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ContactSubmissionController extends Controller
{
    public function __construct(private readonly ContactSubmissionService $submissions) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(ContactSubmission::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->submissions->list($filters)->through(static fn (ContactSubmission $s): array => CmsPresenter::submission($s)));
    }

    /**
     * Opening a submission marks it read.
     */
    public function show(Request $request, ContactSubmission $submission): JsonResponse
    {
        return APIResponse::success(CmsPresenter::submission($this->submissions->markRead($submission, $this->actor($request))));
    }

    public function archive(Request $request, ContactSubmission $submission): JsonResponse
    {
        return APIResponse::success(CmsPresenter::submission($this->submissions->archive($submission, $this->actor($request))), 'Submission archived');
    }

    public function destroy(ContactSubmission $submission): JsonResponse
    {
        $this->submissions->delete($submission);

        return APIResponse::noContent('Submission deleted');
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
