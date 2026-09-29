<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrAppraisal;
use App\Modules\Hr\Models\HrAppraisalScore;
use App\Modules\Hr\Models\HrAppraisalTemplate;
use App\Modules\Hr\Models\HrAppraisalTemplateCriterion;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Appraisal templates and their weighted criteria (spec §58.6). Once a
 * template has been used, its criteria and the template stay as they are,
 * so past appraisals keep their meaning.
 */
final readonly class HrAppraisalTemplateService
{
    /**
     * @param  array<string, mixed>  $data  name, description?, is_active?, criteria?[{label, weight, max_score, sort_order?}]
     */
    public function createTemplate(array $data): HrAppraisalTemplate
    {
        $validated = Validator::make($data, [
            ...$this->rules(true),
            'criteria' => ['sometimes', 'array', 'max:50'],
            'criteria.*.label' => ['required', 'string', 'max:120'],
            'criteria.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'criteria.*.max_score' => ['required', 'numeric', 'gt:0', 'max:999'],
            'criteria.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($validated): HrAppraisalTemplate {
            $template = new HrAppraisalTemplate;
            $template->forceFill(array_intersect_key($validated, array_flip(['name', 'description', 'is_active'])))->save();

            foreach ($validated['criteria'] ?? [] as $i => $criterion) {
                $this->insertCriterion($template, [...$criterion, 'sort_order' => $criterion['sort_order'] ?? $i]);
            }

            return $template->load('criteria');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTemplate(HrAppraisalTemplate $template, array $data): HrAppraisalTemplate
    {
        $template->forceFill(Validator::make($data, $this->rules(false))->validate())->save();

        return $template->load('criteria');
    }

    public function deleteTemplate(HrAppraisalTemplate $template): void
    {
        $this->assertUnused($template);
        $template->delete();
    }

    /**
     * @param  array<string, mixed>  $data  label, weight, max_score, sort_order?
     */
    public function addCriterion(HrAppraisalTemplate $template, array $data): HrAppraisalTemplateCriterion
    {
        $validated = Validator::make($data, [
            'label' => ['required', 'string', 'max:120'],
            'weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'gt:0', 'max:999'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ])->validate();
        $this->assertUnused($template);
        // Added last unless placed explicitly.
        $validated['sort_order'] ??= (int) HrAppraisalTemplateCriterion::query()->where('appraisal_template_id', $template->id)->max('sort_order') + 1;

        return $this->insertCriterion($template, $validated);
    }

    public function removeCriterion(HrAppraisalTemplateCriterion $criterion): void
    {
        $this->assertUnused($criterion->template);

        if (HrAppraisalScore::query()->where('appraisal_template_criterion_id', $criterion->id)->exists()) {
            throw ApiException::unprocessable('criterion_in_use', 'This criterion has been scored.');
        }

        $criterion->delete();
    }

    /**
     * @return Collection<int, HrAppraisalTemplate>
     */
    public function listTemplates(bool $activeOnly = false): Collection
    {
        return HrAppraisalTemplate::query()->with('criteria')->when($activeOnly, static fn ($q) => $q->where('is_active', true))->orderBy('name')->get();
    }

    /**
     * A template can be used once its weights sum to exactly 100.
     */
    public function assertUsable(HrAppraisalTemplate $template): void
    {
        $template->loadMissing('criteria');

        if (! $template->is_active || $template->criteria->isEmpty() || bccomp($template->totalWeight(), '100', 4) !== 0) {
            throw ApiException::unprocessable('appraisal_template_incomplete', 'The template must be active and its criteria weights must sum to 100.', ['total_weight' => $template->totalWeight()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertCriterion(HrAppraisalTemplate $template, array $data): HrAppraisalTemplateCriterion
    {
        $criterion = new HrAppraisalTemplateCriterion;
        $criterion->forceFill([
            'appraisal_template_id' => $template->id,
            'label' => $data['label'],
            'weight' => bcadd((string) $data['weight'], '0', 4),
            'max_score' => bcadd((string) $data['max_score'], '0', 2),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        return $criterion;
    }

    private function assertUnused(HrAppraisalTemplate $template): void
    {
        if (HrAppraisal::query()->where('appraisal_template_id', $template->id)->exists()) {
            throw ApiException::unprocessable('appraisal_template_in_use', 'This template has been used. Deactivate it and create a new one instead.');
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
