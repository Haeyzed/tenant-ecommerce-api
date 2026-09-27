<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Http\ApprovalPresenter;
use App\Modules\Approvals\Models\ApprovalWorkflow;
use App\Modules\Approvals\Services\ApprovalWorkflowService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Approval workflow configuration (spec §60.5).
 */
final class WorkflowController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflows,
        private readonly ApprovalPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'module_key' => ['sometimes', Rule::in(array_keys(ApprovalWorkflowService::registry()))],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->workflows->listWorkflows($filters)->map(fn (ApprovalWorkflow $w): array => $this->presenter->workflow($w))->values());
    }

    /**
     * Body: name, module_key, trigger_conditions, sort_order, steps[{name, approval_mode, approvers[{approver_type, role_id|user_id}]}].
     */
    public function store(Request $request): JsonResponse
    {
        $steps = $request->validate(['steps' => ['required', 'array', 'min:1', 'max:20'], 'steps.*' => ['array']])['steps'];
        $workflow = $this->workflows->createWorkflow(Arr::except($request->all(), ['steps']), array_values($steps));

        return APIResponse::created($this->presenter->workflow($workflow->load('steps.approvers.role:id,name', 'steps.approvers.user:id,name')), 'Workflow created');
    }

    public function show(ApprovalWorkflow $workflow): JsonResponse
    {
        return APIResponse::success($this->presenter->workflow($workflow->load('steps.approvers.role:id,name', 'steps.approvers.user:id,name')));
    }

    public function update(Request $request, ApprovalWorkflow $workflow): JsonResponse
    {
        return APIResponse::success($this->presenter->workflow($this->workflows->updateWorkflow($workflow, $request->all())
            ->load('steps.approvers.role:id,name', 'steps.approvers.user:id,name')), 'Workflow updated');
    }

    public function destroy(ApprovalWorkflow $workflow): JsonResponse
    {
        $this->workflows->deleteWorkflow($workflow);

        return APIResponse::success(null, 'Workflow deleted');
    }

    public function deactivate(ApprovalWorkflow $workflow): JsonResponse
    {
        return APIResponse::success($this->presenter->workflow($this->workflows->deactivateWorkflow($workflow)), 'Workflow deactivated');
    }
}
