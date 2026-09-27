<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Expenses\Http\ExpensePresenter;
use App\Modules\Expenses\Models\IncomeEntry;
use App\Modules\Expenses\Services\IncomeService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Other income (spec §57.8).
 */
final class IncomeController extends Controller
{
    public function __construct(
        private readonly IncomeService $income,
        private readonly ExpensePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([IncomeEntry::PENDING, IncomeEntry::RECEIVED])],
            'income_category_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->income->listIncome($filters)->through(fn (IncomeEntry $i): array => $this->presenter->income($i)));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::created($this->presenter->income($this->income->createIncome($request->all(), $user)), 'Income recorded');
    }

    public function update(Request $request, IncomeEntry $income): JsonResponse
    {
        return APIResponse::success($this->presenter->income($this->income->updateIncome($income, $request->all())), 'Income updated');
    }

    public function destroy(IncomeEntry $income): JsonResponse
    {
        $this->income->deleteIncome($income);

        return APIResponse::success(null, 'Income deleted');
    }

    public function markReceived(Request $request, IncomeEntry $income): JsonResponse
    {
        $accountId = $request->validate(['received_into_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.chart_of_accounts', 'id')]])['received_into_account_id'] ?? null;

        return APIResponse::success($this->presenter->income($this->income->markReceived($income, $accountId === null ? null : Account::query()->findOrFail((int) $accountId))), 'Income received');
    }
}
