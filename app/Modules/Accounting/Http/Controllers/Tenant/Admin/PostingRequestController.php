<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\AccountingPresenter;
use App\Modules\Accounting\Models\AccountingPostingRequest;
use App\Modules\Accounting\Services\AccountingService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The accounting outbox (spec §57.3, §57.8): its requests and a retry of
 * every failed one.
 */
final class PostingRequestController extends Controller
{
    public function __construct(private readonly AccountingPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(AccountingPostingRequest::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = AccountingPostingRequest::query()
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success($requests->through(fn (AccountingPostingRequest $r): array => $this->presenter->request($r)));
    }

    public function retry(AccountingService $accounting): JsonResponse
    {
        return APIResponse::success(['dispatched' => $accounting->retryFailed()], 'Failed postings re-dispatched');
    }
}
