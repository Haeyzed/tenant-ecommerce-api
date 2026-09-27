<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\RoleService;
use App\Modules\Approvals\Http\ApprovalPresenter;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovalRequestService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Acting on approvals (spec §60.5). No route permission: eligibility is
 * checked per step when deciding. A request is visible to its eligible
 * approvers, its requester and holders of approval-workflows.view.
 */
final class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalRequestService $requests,
        private readonly ApprovalPresenter $presenter,
    ) {}

    public function myPending(Request $request): JsonResponse
    {
        return APIResponse::success($this->requests->getPendingApprovalsForUser($this->user($request))
            ->map(fn (ApprovalRequest $r): array => $this->presenter->request($r))->values());
    }

    public function show(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $this->assertVisible($request, $approval);

        return APIResponse::success($this->presenter->request($approval, true));
    }

    public function approve(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:2000']])['note'] ?? null;

        return APIResponse::success($this->presenter->request($this->requests->approveStep($approval, $this->user($request), $note), true), 'Approved');
    }

    public function reject(Request $request, ApprovalRequest $approval): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];

        return APIResponse::success($this->presenter->request($this->requests->rejectStep($approval, $this->user($request), $note), true), 'Rejected');
    }

    private function assertVisible(Request $request, ApprovalRequest $approval): void
    {
        $user = $this->user($request);

        try {
            $viewer = $user->hasPermissionTo('approval-workflows.view', RoleService::GUARD);
        } catch (PermissionDoesNotExist) {
            $viewer = false;
        }

        $involved = $approval->requested_by_user_id === $user->id
            || $this->requests->isEligible($approval, $user)
            || $approval->actions()->where('approved_by_user_id', $user->id)->exists();

        if (! $viewer && ! $involved) {
            throw (new ModelNotFoundException)->setModel(ApprovalRequest::class, [$approval->id]);
        }
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
