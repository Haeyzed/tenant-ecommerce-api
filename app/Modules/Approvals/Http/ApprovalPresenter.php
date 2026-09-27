<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http;

use App\Modules\Approvals\Models\ApprovalAction;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Models\ApprovalStepApprover;
use App\Modules\Approvals\Models\ApprovalWorkflow;
use App\Modules\Approvals\Services\ApprovalRequestService;

/**
 * Response shapes of the approval engine (spec §60).
 */
final readonly class ApprovalPresenter
{
    public function __construct(private ApprovalRequestService $requests) {}

    /**
     * @return array<string, mixed>
     */
    public function workflow(ApprovalWorkflow $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'module_key' => $w->module_key,
            'trigger_conditions' => $w->trigger_conditions,
            'is_active' => $w->is_active,
            'sort_order' => $w->sort_order,
            'pending_requests_count' => $w->getAttributes()['pending_requests_count'] ?? null,
            'steps' => $w->relationLoaded('steps') ? $w->steps->map(fn (ApprovalStep $s): array => $this->step($s))->values()->all() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function step(ApprovalStep $s): array
    {
        return [
            'id' => $s->id,
            'step_order' => $s->step_order,
            'name' => $s->name,
            'approval_mode' => $s->approval_mode,
            'approvers' => $s->approvers->map(static fn (ApprovalStepApprover $a): array => [
                'id' => $a->id,
                'approver_type' => $a->approver_type,
                'role' => $a->role_id === null ? null : ['id' => $a->role_id, 'name' => $a->role?->name],
                'user' => $a->user_id === null ? null : ['id' => $a->user_id, 'name' => $a->user?->name],
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(ApprovalRequest $r, bool $withHistory = false): array
    {
        $r->loadMissing('workflow.steps', 'requester:id,name');
        $step = $r->workflow->steps->firstWhere('step_order', $r->current_step_order);

        $data = [
            'id' => $r->id,
            'workflow' => ['id' => $r->workflow->id, 'name' => $r->workflow->name, 'module_key' => $r->workflow->module_key],
            'subject' => $this->requests->subject($r),
            'approvable_type' => $r->approvable_type,
            'approvable_id' => $r->approvable_id,
            'status' => $r->status,
            'current_step' => $step === null ? null : ['step_order' => $step->step_order, 'name' => $step->name, 'approval_mode' => $step->approval_mode],
            'steps_total' => $r->workflow->steps->count(),
            'requested_by' => $r->requester === null ? null : ['id' => $r->requester->id, 'name' => $r->requester->name],
            'requested_at' => $r->created_at->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
        ];

        if ($withHistory) {
            $data['history'] = $this->requests->getHistory($r)->map(static fn (ApprovalAction $a): array => [
                'step_order' => $a->step_order,
                'step_name' => $a->step_name,
                'decision' => $a->decision,
                'note' => $a->note,
                'by' => ['id' => $a->approved_by_user_id, 'name' => $a->approver?->name],
                'decided_at' => $a->decided_at->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }
}
