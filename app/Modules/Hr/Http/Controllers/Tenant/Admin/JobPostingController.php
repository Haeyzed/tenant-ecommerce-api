<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrJobPosting;
use App\Modules\Hr\Services\HrJobPostingService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class JobPostingController extends Controller
{
    private const array FIELDS = ['title', 'department_id', 'description', 'requirements', 'location', 'employment_type', 'application_deadline'];

    public function __construct(
        private readonly HrJobPostingService $postings,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(HrJobPosting::STATUSES)],
            'department_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->postings->listPostings($filters)->through(fn (HrJobPosting $p): array => $this->presenter->posting($p, true)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->posting($this->postings->createPosting($request->only(self::FIELDS)), true), 'Job posting created');
    }

    public function update(Request $request, HrJobPosting $posting): JsonResponse
    {
        return APIResponse::success($this->presenter->posting($this->postings->updatePosting($posting, $request->only(self::FIELDS)), true), 'Job posting updated');
    }

    public function publish(HrJobPosting $posting): JsonResponse
    {
        return APIResponse::success($this->presenter->posting($this->postings->publishPosting($posting), true), 'Job posting published');
    }

    public function close(HrJobPosting $posting): JsonResponse
    {
        return APIResponse::success($this->presenter->posting($this->postings->closePosting($posting), true), 'Job posting closed');
    }

    public function destroy(HrJobPosting $posting): JsonResponse
    {
        $this->postings->deletePosting($posting);

        return APIResponse::success(null, 'Job posting deleted');
    }
}
