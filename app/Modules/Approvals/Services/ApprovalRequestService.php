<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Services;

use App\Contracts\Approvable;
use App\Modules\Approvals\Models\ApprovalAction;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Models\ApprovalStepApprover;
use App\Modules\Approvals\Models\ApprovalWorkflow;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Runs approval requests (spec §60.3): step by step, any_one or all (A-52);
 * a rejection at any step rejects the request. On resolution the owning
 * service's callback runs in the same transaction, so a callback that
 * fails (for example an exchange item now out of stock) leaves the request
 * pending and the decision unrecorded.
 */
final readonly class ApprovalRequestService
{
    public function __construct(private NotificationDispatchService $notifications) {}

    public function startApproval(ApprovalWorkflow $workflow, Model $approvable, ?User $requestedBy = null): ApprovalRequest
    {
        $request = new ApprovalRequest;
        $request->forceFill([
            'approval_workflow_id' => $workflow->id,
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'requested_by_user_id' => $requestedBy?->id,
            'current_step_order' => 1,
            'status' => ApprovalRequest::PENDING,
        ])->save();

        $request->setRelation('approvable', $approvable);
        $this->notifyStep($request);

        return $request;
    }

    public function approveStep(ApprovalRequest $request, User $approver, ?string $note = null): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalAction::APPROVED, $note);
    }

    public function rejectStep(ApprovalRequest $request, User $approver, ?string $note = null): ApprovalRequest
    {
        return $this->decide($request, $approver, ApprovalAction::REJECTED, $note);
    }

    /**
     * The record was cancelled first (§60.3 step 6); no callback runs.
     */
    public function cancelApproval(ApprovalRequest $request): void
    {
        ApprovalRequest::query()->whereKey($request->id)->where('status', ApprovalRequest::PENDING)
            ->update(['status' => ApprovalRequest::CANCELLED, 'resolved_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Every pending request at a step the user may act on now, and has not
     * already approved (the "My Approvals" inbox).
     *
     * @return SupportCollection<int, ApprovalRequest>
     */
    public function getPendingApprovalsForUser(User $user): SupportCollection
    {
        $roleIds = $user->roles()->pluck('id')->all();

        $candidates = ApprovalRequest::query()
            ->with(['workflow.steps.approvers.role', 'requester:id,name', 'actions'])
            ->where('status', ApprovalRequest::PENDING)
            ->whereHas('workflow.steps', static fn ($steps) => $steps
                ->whereColumn('approval_steps.step_order', 'approval_requests.current_step_order')
                ->whereHas('approvers', static fn ($a) => $a->where(static fn ($q) => $q
                    ->where(static fn ($u) => $u->where('approver_type', ApprovalStepApprover::USER)->where('user_id', $user->id))
                    ->orWhere(static fn ($r) => $r->where('approver_type', ApprovalStepApprover::ROLE)->whereIn('role_id', $roleIds === [] ? [0] : $roleIds)))))
            ->orderBy('created_at')
            ->get();

        return $candidates->filter(fn (ApprovalRequest $r): bool => ! $this->hasActed($r, $this->currentStep($r), $user))->values();
    }

    /**
     * @return Collection<int, ApprovalAction>
     */
    public function getHistory(ApprovalRequest $request): Collection
    {
        return $request->actions()->with('approver:id,name')->get();
    }

    public function isEligible(ApprovalRequest $request, User $user): bool
    {
        $step = $this->currentStep($request);

        return $request->status === ApprovalRequest::PENDING && $step !== null
            && $step->approvers->contains(static fn (ApprovalStepApprover $a): bool => $a->matches($user));
    }

    private function decide(ApprovalRequest $request, User $approver, string $decision, ?string $note): ApprovalRequest
    {
        $outcome = null;

        DB::connection('tenant')->transaction(function () use ($request, $approver, $decision, $note, &$outcome): void {
            /** @var ApprovalRequest $locked */
            $locked = ApprovalRequest::query()->with('workflow')->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== ApprovalRequest::PENDING) {
                throw ApiException::conflict('approval_resolved', 'This approval request is already '.$locked->status.'.');
            }

            $step = $this->currentStep($locked) ?? throw ApiException::conflict('approval_step_missing', 'The current step of this workflow no longer exists.');

            if (! $step->approvers->contains(static fn (ApprovalStepApprover $a): bool => $a->matches($approver))) {
                throw ApiException::forbidden('not_an_approver', 'You are not an approver for the current step of this request.');
            }

            if ($this->hasActed($locked, $step, $approver)) {
                throw ApiException::conflict('already_decided', 'You have already approved this step.');
            }

            $action = new ApprovalAction;
            $action->forceFill([
                'approval_request_id' => $locked->id,
                'approval_step_id' => $step->id,
                'step_name' => $step->name,
                'step_order' => $step->step_order,
                'approved_by_user_id' => $approver->id,
                'decision' => $decision,
                'note' => $note === null ? null : mb_substr($note, 0, 2000),
                'decided_at' => now(),
            ])->save();

            $approvable = $this->approvable($locked);

            if ($decision === ApprovalAction::REJECTED) {
                $locked->forceFill(['status' => ApprovalRequest::REJECTED, 'resolved_at' => now()])->save();
                $this->owner($locked)->onApprovalRejected($approvable, $note);
                $outcome = 'rejected';
            } elseif ($this->stepComplete($locked->refresh(), $step)) {
                $next = $locked->workflow->steps()->where('step_order', '>', $step->step_order)->orderBy('step_order')->first();

                if ($next === null) {
                    $locked->forceFill(['status' => ApprovalRequest::APPROVED, 'resolved_at' => now()])->save();
                    $this->owner($locked)->onApprovalGranted($approvable);
                    $outcome = 'approved';
                } else {
                    $locked->forceFill(['current_step_order' => $next->step_order])->save();
                    $outcome = 'advanced';
                }
            }

            $request->setRawAttributes($locked->getAttributes(), true);
            $request->setRelation('approvable', $approvable);
        });

        match ($outcome) {
            'advanced' => $this->notifyStep($request),
            'approved' => $this->notifyRequester($request, 'approval.request_approved', ['reason' => '']),
            'rejected' => $this->notifyRequester($request, 'approval.request_rejected', ['reason' => $note ?? 'No reason given']),
            default => null,
        };

        return $request;
    }

    /**
     * any_one: one approval. all: every approver row satisfied, a role row
     * by any one holder of that role (A-52).
     */
    private function stepComplete(ApprovalRequest $request, ApprovalStep $step): bool
    {
        $approvals = ApprovalAction::query()->with('approver')->where('approval_request_id', $request->id)
            ->where('approval_step_id', $step->id)->where('decision', ApprovalAction::APPROVED)->get();

        if ($step->approval_mode === ApprovalStep::ANY_ONE) {
            return $approvals->isNotEmpty();
        }

        return $step->approvers->every(static fn (ApprovalStepApprover $row): bool => $approvals->contains(
            static fn (ApprovalAction $a): bool => $a->approver !== null && $row->matches($a->approver),
        ));
    }

    private function hasActed(ApprovalRequest $request, ?ApprovalStep $step, User $user): bool
    {
        return $step !== null && ApprovalAction::query()->where('approval_request_id', $request->id)
            ->where('approval_step_id', $step->id)->where('approved_by_user_id', $user->id)->exists();
    }

    private function currentStep(ApprovalRequest $request): ?ApprovalStep
    {
        return ApprovalStep::query()->with('approvers.role')
            ->where('approval_workflow_id', $request->approval_workflow_id)
            ->where('step_order', $request->current_step_order)->first();
    }

    private function approvable(ApprovalRequest $request): Model
    {
        $request->loadMissing('approvable');

        return $request->approvable ?? throw ApiException::conflict('approvable_missing', 'The record of this request no longer exists.');
    }

    private function owner(ApprovalRequest $request): Approvable
    {
        $definition = ApprovalWorkflowService::registry()[$request->workflow->module_key] ?? null;

        if ($definition === null) {
            throw ApiException::conflict('approval_module_unknown', 'This workflow\'s module is not registered.');
        }

        /** @var Approvable */
        return app($definition['service']);
    }

    /**
     * approval.step_pending to every eligible approver of the current step.
     */
    private function notifyStep(ApprovalRequest $request): void
    {
        $step = $this->currentStep($request);

        if ($step === null) {
            return;
        }

        $recipients = User::query()->where('is_active', true)->get()
            ->filter(static fn (User $u): bool => $step->approvers->contains(static fn (ApprovalStepApprover $a): bool => $a->matches($u)))
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $this->notifications->dispatch('approval.step_pending', $recipients, [
            'subject' => $this->subject($request),
            'requester_name' => $request->requester->name ?? 'A customer',
        ], null, ['approval_request_id' => $request->id]);
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function notifyRequester(ApprovalRequest $request, string $key, array $variables): void
    {
        $request->loadMissing('requester');

        if ($request->requester === null) {
            return;
        }

        $this->notifications->dispatch($key, $request->requester, ['subject' => $this->subject($request), ...$variables], null, ['approval_request_id' => $request->id]);
    }

    public function subject(ApprovalRequest $request): string
    {
        $request->loadMissing('workflow', 'approvable');

        return $request->approvable === null ? 'a request' : $this->owner($request)->approvalSubject($request->approvable);
    }
}
