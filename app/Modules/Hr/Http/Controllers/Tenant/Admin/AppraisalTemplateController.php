<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrAppraisalTemplate;
use App\Modules\Hr\Models\HrAppraisalTemplateCriterion;
use App\Modules\Hr\Services\HrAppraisalTemplateService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppraisalTemplateController extends Controller
{
    public function __construct(
        private readonly HrAppraisalTemplateService $templates,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->templates->listTemplates()->map(fn (HrAppraisalTemplate $t): array => $this->presenter->template($t))->all());
    }

    /**
     * Body: name, description?, is_active?, criteria?[{label, weight, max_score, sort_order?}]
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->template($this->templates->createTemplate($request->only(['name', 'description', 'is_active', 'criteria']))), 'Template created');
    }

    public function update(Request $request, HrAppraisalTemplate $template): JsonResponse
    {
        return APIResponse::success($this->presenter->template($this->templates->updateTemplate($template, $request->only(['name', 'description', 'is_active']))), 'Template updated');
    }

    public function destroy(HrAppraisalTemplate $template): JsonResponse
    {
        $this->templates->deleteTemplate($template);

        return APIResponse::success(null, 'Template deleted');
    }

    /**
     * Body: label, weight, max_score, sort_order?
     */
    public function addCriterion(Request $request, HrAppraisalTemplate $template): JsonResponse
    {
        $this->templates->addCriterion($template, $request->only(['label', 'weight', 'max_score', 'sort_order']));

        return APIResponse::created($this->presenter->template($template->fresh('criteria')), 'Criterion added');
    }

    public function removeCriterion(HrAppraisalTemplateCriterion $criterion): JsonResponse
    {
        $template = $criterion->template;
        $this->templates->removeCriterion($criterion);

        return APIResponse::success($this->presenter->template($template->fresh('criteria')), 'Criterion removed');
    }
}
