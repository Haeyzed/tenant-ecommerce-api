<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Http\ApprovalPresenter;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Models\ApprovalWorkflow;
use App\Modules\Approvals\Services\ApprovalWorkflowService;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workflow steps (spec §60.5): add, remove (resequenced) and reorder.
 */
final class WorkflowStepController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflows,
        private readonly ApprovalPresenter $presenter,
    ) {}

    /**
     * Body: name, approval_mode (any_one|all), approvers[{approver_type, role_id|user_id}].
     */
    public function store(Request $request, ApprovalWorkflow $workflow): JsonResponse
    {
        return APIResponse::created($this->presenter->step($this->workflows->addStep($workflow, $request->all())), 'Step added');
    }

    public function destroy(ApprovalWorkflow $workflow, ApprovalStep $step): JsonResponse
    {
        if ($step->approval_workflow_id !== $workflow->id) {
            throw (new ModelNotFoundException)->setModel(ApprovalStep::class, [$step->id]);
        }

        $this->workflows->removeStep($step);

        return APIResponse::success($this->presenter->workflow($workflow->refresh()->load('steps.approvers.role:id,name', 'steps.approvers.user:id,name')), 'Step removed');
    }

    public function reorder(Request $request, ApprovalWorkflow $workflow): JsonResponse
    {
        $ids = $request->validate(['step_ids' => ['required', 'array', 'min:1'], 'step_ids.*' => ['integer']])['step_ids'];
        $this->workflows->reorderSteps($workflow, array_values($ids));

        return APIResponse::success($this->presenter->workflow($workflow->refresh()->load('steps.approvers.role:id,name', 'steps.approvers.user:id,name')), 'Steps reordered');
    }
}
