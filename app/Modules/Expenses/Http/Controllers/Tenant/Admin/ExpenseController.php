<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Expenses\Http\ExpensePresenter;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Expenses (spec §57.8). A receipt may be attached as `receipt`.
 */
final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ExpensePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([Expense::PENDING, Expense::PAID])],
            'expense_category_id' => ['sometimes', 'integer'],
            'biller_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->expenses->listExpenses($filters)->through(fn (Expense $e): array => $this->presenter->expense($e)));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::created($this->presenter->expense($this->expenses->createExpense(Arr::except($request->all(), ['receipt']), $user, $this->receipt($request))), 'Expense recorded');
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        return APIResponse::success($this->presenter->expense($this->expenses->updateExpense($expense, Arr::except($request->all(), ['receipt']), $this->receipt($request))), 'Expense updated');
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->expenses->deleteExpense($expense);

        return APIResponse::success(null, 'Expense deleted');
    }

    public function markPaid(Request $request, Expense $expense): JsonResponse
    {
        $accountId = $request->validate(['paid_from_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.chart_of_accounts', 'id')]])['paid_from_account_id'] ?? null;

        return APIResponse::success($this->presenter->expense($this->expenses->markPaid($expense, $accountId === null ? null : Account::query()->findOrFail((int) $accountId))), 'Expense paid');
    }

    private function receipt(Request $request): ?UploadedFile
    {
        if (! $request->hasFile('receipt')) {
            return null;
        }

        $request->validate(['receipt' => UploadRules::document()]);
        /** @var UploadedFile $file */
        $file = $request->file('receipt');
        app(StorageQuota::class)->assertAllows($file);

        return $file;
    }
}
