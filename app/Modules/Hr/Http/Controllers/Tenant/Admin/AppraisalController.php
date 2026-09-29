<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\Concerns\ResolvesActingEmployee;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrAppraisal;
use App\Modules\Hr\Models\HrAppraisalTemplate;
use App\Modules\Hr\Models\HrAppraisalTemplateCriterion;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Services\HrAppraisalService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appraisals (spec §58.6). Acknowledging is self-service for the appraised
 * employee's linked user; hr.appraisals.acknowledge lets staff record it
 * for a standalone employee.
 */
final class AppraisalController extends Controller
{
    use ResolvesActingEmployee;

    public const string ACKNOWLEDGE = 'hr.appraisals.acknowledge';

    public function __construct(
        private readonly HrAppraisalService $appraisals,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(HrEmployee $employee): JsonResponse
    {
        return APIResponse::success($this->appraisals->getAppraisalsForEmployee($employee)->map(fn (HrAppraisal $a): array => $this->presenter->appraisal($a))->all());
    }

    /**
     * Body: appraisal_template_id, period_start, period_end, reviewer_user_id? (default: the caller).
     */
    public function store(Request $request, HrEmployee $employee): JsonResponse
    {
        $validated = $request->validate([
            'appraisal_template_id' => ['required', 'integer'],
            'reviewer_user_id' => ['sometimes', 'integer'],
            'period_start' => ['required', 'string'],
            'period_end' => ['required', 'string'],
        ]);
        $template = HrAppraisalTemplate::query()->findOrFail((int) $validated['appraisal_template_id']);
        /** @var User $reviewer */
        $reviewer = isset($validated['reviewer_user_id']) ? User::query()->findOrFail((int) $validated['reviewer_user_id']) : $request->user();

        return APIResponse::created($this->presenter->appraisal($this->appraisals->startAppraisal($employee, $template, $reviewer, $validated['period_start'], $validated['period_end'])), 'Appraisal started');
    }

    /**
     * Body: scores[{criterion_id, score, comments?}]
     */
    public function score(Request $request, HrAppraisal $appraisal): JsonResponse
    {
        $validated = $request->validate([
            'scores' => ['required', 'array', 'min:1', 'max:50'],
            'scores.*.criterion_id' => ['required', 'integer'],
            'scores.*.score' => ['required', 'numeric'],
            'scores.*.comments' => ['sometimes', 'nullable', 'string'],
        ]);

        foreach ($validated['scores'] as $row) {
            $criterion = HrAppraisalTemplateCriterion::query()->findOrFail((int) $row['criterion_id']);
            $this->appraisals->scoreCriterion($appraisal, $criterion, (string) $row['score'], $row['comments'] ?? null);
        }

        return APIResponse::success($this->presenter->appraisal($appraisal->fresh()), 'Scores saved');
    }

    /**
     * Body: overall_comments?
     */
    public function submit(Request $request, HrAppraisal $appraisal): JsonResponse
    {
        $comments = $request->input('overall_comments');

        return APIResponse::success($this->presenter->appraisal($this->appraisals->submitAppraisal($appraisal, is_string($comments) ? $comments : null)), 'Appraisal submitted');
    }

    public function acknowledge(Request $request, HrAppraisal $appraisal): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $appraisal->loadMissing('employee');

        if (! $this->isOwn($request, $appraisal->employee) && ! $user->hasPermissionTo(self::ACKNOWLEDGE, 'staff')) {
            throw ApiException::forbidden('forbidden', 'Only the appraised employee may acknowledge this appraisal.', ['permission' => self::ACKNOWLEDGE]);
        }

        return APIResponse::success($this->presenter->appraisal($this->appraisals->acknowledgeAppraisal($appraisal)), 'Appraisal acknowledged');
    }
}
