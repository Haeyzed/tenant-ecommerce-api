<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrAppraisal;
use App\Modules\Hr\Models\HrAppraisalScore;
use App\Modules\Hr\Models\HrAppraisalTemplate;
use App\Modules\Hr\Models\HrAppraisalTemplateCriterion;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Appraisals (spec §58.6): draft (scored criterion by criterion), submitted
 * with overall_score = Σ(score / max_score × weight), then acknowledged by
 * the employee.
 */
final readonly class HrAppraisalService
{
    public function __construct(private HrAppraisalTemplateService $templates) {}

    public function startAppraisal(HrEmployee $employee, HrAppraisalTemplate $template, User $reviewer, string $periodStart, string $periodEnd): HrAppraisal
    {
        Validator::make(['period_start' => $periodStart, 'period_end' => $periodEnd], [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ])->validate();

        if ($employee->status !== HrEmployee::ACTIVE) {
            throw ApiException::unprocessable('employee_inactive', 'This employee is not active.');
        }

        if (! $reviewer->is_active) {
            throw ApiException::unprocessable('reviewer_inactive', 'The reviewer must be an active user.');
        }

        $this->templates->assertUsable($template);

        $appraisal = new HrAppraisal;
        $appraisal->forceFill([
            'employee_id' => $employee->id,
            'appraisal_template_id' => $template->id,
            'reviewer_user_id' => $reviewer->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => HrAppraisal::DRAFT,
        ])->save();

        return $appraisal;
    }

    /**
     * Sets or replaces the score of one criterion of the appraisal's template.
     */
    public function scoreCriterion(HrAppraisal $appraisal, HrAppraisalTemplateCriterion $criterion, string $score, ?string $comments = null): HrAppraisalScore
    {
        if ($criterion->appraisal_template_id !== $appraisal->appraisal_template_id) {
            throw ApiException::unprocessable('criterion_not_in_template', 'This criterion is not part of the appraisal\'s template.');
        }

        Validator::make(['score' => $score, 'comments' => $comments], [
            'score' => ['required', 'numeric', 'min:0', 'max:'.$criterion->max_score, 'decimal:0,2'],
            'comments' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($appraisal, $criterion, $score, $comments): HrAppraisalScore {
            $this->lockDraft($appraisal);
            $row = HrAppraisalScore::query()->where('appraisal_id', $appraisal->id)->where('appraisal_template_criterion_id', $criterion->id)->first() ?? new HrAppraisalScore;
            $row->forceFill(['appraisal_id' => $appraisal->id, 'appraisal_template_criterion_id' => $criterion->id,
                'score' => bcadd($score, '0', 2), 'comments' => $comments])->save();

            return $row;
        });
    }

    /**
     * Every criterion must be scored; the overall score is 0–100.
     */
    public function submitAppraisal(HrAppraisal $appraisal, ?string $overallComments = null): HrAppraisal
    {
        Validator::make(['overall_comments' => $overallComments], ['overall_comments' => ['nullable', 'string', 'max:10000']])->validate();

        return DB::connection('tenant')->transaction(function () use ($appraisal, $overallComments): HrAppraisal {
            $locked = $this->lockDraft($appraisal);
            $criteria = HrAppraisalTemplateCriterion::query()->where('appraisal_template_id', $locked->appraisal_template_id)->get()->keyBy('id');
            $scores = HrAppraisalScore::query()->where('appraisal_id', $locked->id)->get()->keyBy('appraisal_template_criterion_id');
            $missing = $criteria->keys()->diff($scores->keys());

            if ($missing->isNotEmpty()) {
                throw ApiException::unprocessable('appraisal_incomplete', 'Score every criterion before submitting.', ['missing_criterion_ids' => $missing->values()->all()]);
            }

            $overall = '0';

            foreach ($criteria as $id => $criterion) {
                $overall = bcadd($overall, bcmul(bcdiv((string) $scores[$id]->score, (string) $criterion->max_score, 10), (string) $criterion->weight, 10), 10);
            }

            $locked->forceFill([
                'status' => HrAppraisal::SUBMITTED,
                // Half up to two places (the score is never negative).
                'overall_score' => bcadd($overall, '0.005', 2),
                'overall_comments' => $overallComments,
                'submitted_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function acknowledgeAppraisal(HrAppraisal $appraisal): HrAppraisal
    {
        return DB::connection('tenant')->transaction(static function () use ($appraisal): HrAppraisal {
            /** @var HrAppraisal $locked */
            $locked = HrAppraisal::query()->lockForUpdate()->findOrFail($appraisal->id);

            if ($locked->status !== HrAppraisal::SUBMITTED) {
                throw ApiException::invalidTransition($locked->status, HrAppraisal::ACKNOWLEDGED);
            }

            $locked->forceFill(['status' => HrAppraisal::ACKNOWLEDGED, 'acknowledged_at' => now()])->save();

            return $locked;
        });
    }

    /**
     * @return Collection<int, HrAppraisal>
     */
    public function getAppraisalsForEmployee(HrEmployee $employee): Collection
    {
        return HrAppraisal::query()->with(['template.criteria', 'reviewer:id,name', 'scores'])
            ->where('employee_id', $employee->id)->orderByDesc('period_start')->orderByDesc('id')->get();
    }

    private function lockDraft(HrAppraisal $appraisal): HrAppraisal
    {
        /** @var HrAppraisal $locked */
        $locked = HrAppraisal::query()->lockForUpdate()->findOrFail($appraisal->id);

        if ($locked->status !== HrAppraisal::DRAFT) {
            throw ApiException::unprocessable('appraisal_submitted', 'This appraisal has been submitted and can no longer change.');
        }

        return $locked;
    }
}
