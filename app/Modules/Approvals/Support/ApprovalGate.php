<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovalRequestService;
use App\Modules\Approvals\Services\ApprovalWorkflowService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;

/**
 * What an approvable module calls (spec §60.3). hold() routes a record
 * through a matching workflow, or returns null so the module's own
 * single-step flow runs unchanged; assertNoPending() is the 409 of the
 * module's direct approve and reject actions.
 */
final readonly class ApprovalGate
{
    public function __construct(
        private ApprovalWorkflowService $workflows,
        private ApprovalRequestService $requests,
    ) {}

    /**
     * Starts an approval when a workflow matches. A record that is sent
     * again (a resubmitted review) replaces its previous pending request.
     */
    public function hold(string $moduleKey, Model $record, ?User $requestedBy = null): ?ApprovalRequest
    {
        $workflow = $this->workflows->findMatchingWorkflow($moduleKey, $record);

        if ($workflow === null) {
            return null;
        }

        $this->cancel($record);
        $requestedBy ??= auth()->user() instanceof User ? auth()->user() : null;

        return $this->requests->startApproval($workflow, $record, $requestedBy);
    }

    public function pending(Model $record): ?ApprovalRequest
    {
        return ApprovalRequest::query()->where('approvable_type', $record->getMorphClass())->where('approvable_id', $record->getKey())
            ->where('status', ApprovalRequest::PENDING)->first();
    }

    public function assertNoPending(Model $record): void
    {
        $pending = $this->pending($record);

        if ($pending !== null) {
            throw ApiException::conflict('approval_pending', 'This record is going through an approval workflow. Decide it from the approvals inbox.', [
                'approval_request_id' => $pending->id,
            ]);
        }
    }

    public function cancel(Model $record): void
    {
        $pending = $this->pending($record);

        if ($pending !== null) {
            $this->requests->cancelApproval($pending);
        }
    }
}
