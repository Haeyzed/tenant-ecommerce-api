<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrJobPosting;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Job postings (spec §58.7): drafted by staff, opened on the public
 * careers page, then closed.
 */
final readonly class HrJobPostingService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createPosting(array $data): HrJobPosting
    {
        $posting = new HrJobPosting;
        $posting->forceFill([...$this->validate($data, true), 'status' => HrJobPosting::DRAFT])->save();

        return $posting;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePosting(HrJobPosting $posting, array $data): HrJobPosting
    {
        if ($posting->status === HrJobPosting::CLOSED) {
            throw ApiException::unprocessable('job_posting_closed', 'A closed posting cannot change.');
        }

        $posting->forceFill($this->validate($data, false))->save();

        return $posting;
    }

    public function publishPosting(HrJobPosting $posting): HrJobPosting
    {
        if ($posting->status !== HrJobPosting::DRAFT) {
            throw ApiException::invalidTransition($posting->status, HrJobPosting::OPEN);
        }

        if ($posting->application_deadline !== null && $posting->application_deadline->isBefore(today())) {
            throw ApiException::unprocessable('job_posting_deadline_passed', 'The application deadline has passed.');
        }

        $posting->forceFill(['status' => HrJobPosting::OPEN, 'posted_at' => now()])->save();

        return $posting;
    }

    public function closePosting(HrJobPosting $posting): HrJobPosting
    {
        if ($posting->status !== HrJobPosting::OPEN) {
            throw ApiException::invalidTransition($posting->status, HrJobPosting::CLOSED);
        }

        $posting->forceFill(['status' => HrJobPosting::CLOSED, 'closed_at' => now()])->save();

        return $posting;
    }

    public function deletePosting(HrJobPosting $posting): void
    {
        if ($posting->status !== HrJobPosting::DRAFT) {
            throw ApiException::unprocessable('job_posting_not_draft', 'Only a draft posting can be deleted. Close it instead.');
        }

        $posting->delete();
    }

    /**
     * @param  array{status?: string, department_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, HrJobPosting>
     */
    public function listPostings(array $filters = []): LengthAwarePaginator
    {
        return HrJobPosting::query()->with('department:id,name')->withCount('applications')
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['department_id']), static fn ($q) => $q->where('department_id', $filters['department_id']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Public: open postings whose deadline has not passed.
     *
     * @param  array{department_id?: int, location?: string, employment_type?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, HrJobPosting>
     */
    public function listOpenPostings(array $filters = []): LengthAwarePaginator
    {
        return HrJobPosting::query()->with('department:id,name')->where('status', HrJobPosting::OPEN)
            ->where(static fn ($q) => $q->whereNull('application_deadline')->orWhereDate('application_deadline', '>=', today()))
            ->when(isset($filters['department_id']), static fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(isset($filters['location']), static fn ($q) => $q->where('location', $filters['location']))
            ->when(isset($filters['employment_type']), static fn ($q) => $q->where('employment_type', $filters['employment_type']))
            ->orderByDesc('posted_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * Public: an open posting, else 404.
     */
    public function getPostingBySlug(string $slug): HrJobPosting
    {
        return HrJobPosting::query()->with('department:id,name')->where('slug', $slug)->where('status', HrJobPosting::OPEN)->first()
            ?? throw new NotFoundHttpException('Not found.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.hr_departments', 'id')],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:20000'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employment_type' => [$creating ? 'required' : 'sometimes', Rule::in(HrEmployee::EMPLOYMENT_TYPES)],
            'application_deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])->validate();
    }
}
