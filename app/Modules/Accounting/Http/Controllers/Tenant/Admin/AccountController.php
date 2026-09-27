<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\AccountingPresenter;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountCategory;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The chart of accounts (spec §57.8).
 */
final class AccountController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountsService $accounts,
        private readonly AccountingPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'account_type' => ['sometimes', Rule::in(AccountCategory::TYPES)],
            'category_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success($this->accounts->listAccounts($filters)->map(fn (Account $a): array => $this->presenter->account($a))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->account($this->accounts->createAccount($request->all())), 'Account created');
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        return APIResponse::success($this->presenter->account($this->accounts->updateAccount($account->load('category'), $request->all())), 'Account updated');
    }

    public function deactivate(Account $account): JsonResponse
    {
        return APIResponse::success($this->presenter->account($this->accounts->deactivateAccount($account)->load('category')), 'Account deactivated');
    }
}
