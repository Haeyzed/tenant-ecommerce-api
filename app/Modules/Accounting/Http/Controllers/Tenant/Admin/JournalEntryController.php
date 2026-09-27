<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\AccountingPresenter;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Journal entries (spec §57.8): the list, manual entries and reversals.
 */
final class JournalEntryController extends Controller
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly AccountingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'account_id' => ['sometimes', 'integer'],
            'source' => ['sometimes', Rule::in([JournalEntry::SYSTEM, JournalEntry::MANUAL, JournalEntry::REVERSAL])],
            'reference_type' => ['sometimes', 'string', 'max:64'],
            'reference_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $entries = JournalEntry::query()->with(['lines.account:id,code,name', 'creator:id,name'])
            ->when(isset($filters['from']), static fn (Builder $q) => $q->whereDate('entry_date', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn (Builder $q) => $q->whereDate('entry_date', '<=', $filters['to']))
            ->when(isset($filters['account_id']), static fn (Builder $q) => $q->whereHas('lines', static fn (Builder $l) => $l->where('account_id', $filters['account_id'])))
            ->when(isset($filters['source']), static fn (Builder $q) => $q->where('source', $filters['source']))
            ->when(isset($filters['reference_type']), static fn (Builder $q) => $q->where('reference_type', $filters['reference_type']))
            ->when(isset($filters['reference_id']), static fn (Builder $q) => $q->where('reference_id', $filters['reference_id']))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success($entries->through(fn (JournalEntry $e): array => $this->presenter->entry($e)));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::created($this->presenter->entry($this->accounting->postManualEntry($request->all(), $user)->load('lines.account:id,code,name')), 'Entry posted');
    }

    public function show(JournalEntry $entry): JsonResponse
    {
        return APIResponse::success($this->presenter->entry($entry->load(['lines.account:id,code,name', 'creator:id,name'])));
    }

    public function reverse(Request $request, JournalEntry $entry): JsonResponse
    {
        $reason = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:200']])['reason'] ?? null;

        /** @var User $user */
        $user = $request->user();

        return APIResponse::created($this->presenter->entry($this->accounting->reverseEntry($entry, $reason, $user)->load('lines.account:id,code,name')), 'Entry reversed');
    }
}
