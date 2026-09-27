<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Expense categories and expenses (spec §57.4, §57.7). Pending expenses are
 * edited or deleted freely; markPaid() is one-way and records the
 * postExpense request (a no-op unless `accounting` is enabled).
 */
final readonly class ExpenseService
{
    public function __construct(
        private AccountingOutbox $outbox,
        private TenantSettingsService $settings,
    ) {}

    // ---- Categories ------------------------------------------------------

    /**
     * @return Collection<int, ExpenseCategory>
     */
    public function listCategories(): Collection
    {
        return ExpenseCategory::query()->with('account:id,code,name')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data  name, account_id (an active expense account), is_active
     */
    public function createCategory(array $data): ExpenseCategory
    {
        $validated = $this->validateCategory($data, true);
        $category = new ExpenseCategory($validated);
        $category->forceFill(['is_active' => (bool) ($validated['is_active'] ?? true)])->save();

        return $category->load('account:id,code,name');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(ExpenseCategory $category, array $data): ExpenseCategory
    {
        $validated = $this->validateCategory($data, false);
        $category->fill($validated);

        if (array_key_exists('is_active', $validated)) {
            $category->forceFill(['is_active' => (bool) $validated['is_active']]);
        }

        $category->save();

        return $category->load('account:id,code,name');
    }

    // ---- Expenses ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    public function createExpense(array $data, User $by, ?UploadedFile $receipt = null): Expense
    {
        $validated = $this->validateExpense($data, true);

        return DB::connection('tenant')->transaction(function () use ($validated, $by, $receipt): Expense {
            $expense = new Expense($validated);
            $expense->forceFill([
                'amount' => Money::round(Money::normalize((string) $validated['amount']), $this->currency()),
                'currency_code' => $this->currency(),
                'status' => Expense::PENDING,
                'created_by_user_id' => $by->id,
            ])->save();

            if ($receipt !== null) {
                $expense->addMedia($receipt)->toMediaCollection('receipt');
            }

            return $expense->load(['category:id,name', 'biller:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateExpense(Expense $expense, array $data, ?UploadedFile $receipt = null): Expense
    {
        $this->assertPending($expense);
        $validated = $this->validateExpense($data, false, $expense);

        if (isset($validated['amount'])) {
            $validated['amount'] = Money::round(Money::normalize((string) $validated['amount']), $this->currency());
        }

        $expense->fill($validated)->save();

        if ($receipt !== null) {
            $expense->addMedia($receipt)->toMediaCollection('receipt');
        }

        return $expense->load(['category:id,name', 'biller:id,name']);
    }

    public function deleteExpense(Expense $expense): void
    {
        $this->assertPending($expense);
        $expense->clearMediaCollection('receipt');
        $expense->delete();
    }

    /**
     * One-way (§57.4); posts Dr expense account, Cr the paid-from account.
     */
    public function markPaid(Expense $expense, ?Account $fromAccount = null): Expense
    {
        if ($fromAccount !== null) {
            $this->assertCashLike($fromAccount);
        }

        DB::connection('tenant')->transaction(function () use ($expense, $fromAccount): void {
            /** @var Expense $locked */
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $this->assertPending($locked);

            $locked->forceFill(['status' => Expense::PAID, 'paid_at' => now(), 'paid_from_account_id' => $fromAccount?->id])->save();
            $this->outbox->record('postExpense', $locked, $locked->paid_at, 'expense:'.$locked->id);
            $expense->setRawAttributes($locked->getAttributes(), true);
        });

        return $expense->load(['category:id,name', 'biller:id,name']);
    }

    /**
     * @param  array{status?: string, expense_category_id?: int, biller_id?: int, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Expense>
     */
    public function listExpenses(array $filters = []): LengthAwarePaginator
    {
        return Expense::query()->with(['category:id,name', 'biller:id,name', 'creator:id,name'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['expense_category_id']), static fn ($q) => $q->where('expense_category_id', $filters['expense_category_id']))
            ->when(isset($filters['biller_id']), static fn ($q) => $q->where('biller_id', $filters['biller_id']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('expense_date', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('expense_date', '<=', $filters['to']))
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Expenses dated today in the tenant timezone (§57.7; AI assistant).
     *
     * @return array{date: string, currency: string, total: string, expenses: list<array<string, mixed>>}
     */
    public function getTodayExpenses(): array
    {
        $today = Carbon::now((string) ($this->settings->get('timezone') ?: 'UTC'))->toDateString();
        $rows = Expense::query()->with('category:id,name')->whereDate('expense_date', $today)->orderBy('id')->get();

        return [
            'date' => $today,
            'currency' => $this->currency(),
            'total' => $rows->reduce(static fn (string $sum, Expense $e): string => Money::add($sum, (string) $e->amount), Money::normalize(0)),
            'expenses' => $rows->map(static fn (Expense $e): array => ['id' => $e->id, 'category' => $e->category->name, 'amount' => (string) $e->amount, 'status' => $e->status, 'description' => $e->description])->all(),
        ];
    }

    private function assertPending(Expense $expense): void
    {
        if ($expense->status !== Expense::PENDING) {
            throw ApiException::unprocessable('expense_paid', 'A paid expense cannot be changed. Reverse its journal entry and record a new expense.');
        }
    }

    private function assertCashLike(Account $account): void
    {
        $account->loadMissing('category');

        if (! $account->is_active || $account->category->account_type !== 'asset') {
            throw ApiException::unprocessable('account_invalid', 'Pay from an active asset account (cash or bank).');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateCategory(array $data, bool $creating): array
    {
        return validator($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.chart_of_accounts', 'id')->where('is_active', true)
                ->whereIn('account_category_id', DB::connection('tenant')->table('account_categories')->where('account_type', 'expense')->pluck('id')->all())],
            'is_active' => ['sometimes', 'boolean'],
        ], ['account_id.exists' => 'Choose an active expense account.'])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateExpense(array $data, bool $creating, ?Expense $expense = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $validated = validator($data, [
            'expense_category_id' => [$req, 'integer', Rule::exists('tenant.expense_categories', 'id')->where('is_active', true)],
            'biller_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.billers', 'id')->where('is_active', true)],
            // A supplier (§49) can be named while purchasing is enabled.
            'supplier_id' => $this->purchasingEnabled()
                ? ['sometimes', 'nullable', 'integer', Rule::exists('tenant.suppliers', 'id')->whereNull('deleted_at')]
                : ['prohibited'],
            'amount' => [$req, 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999999'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'expense_date' => [$req, 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], ['supplier_id.prohibited' => 'Expenses can name a supplier once purchasing is enabled.'])->validate();

        // Base currency only until multi-currency (§48).
        if (isset($validated['currency_code']) && strtoupper((string) $validated['currency_code']) !== $this->currency()) {
            throw ApiException::unprocessable('currency_not_supported', 'Expenses are recorded in '.$this->currency().'.');
        }

        unset($validated['currency_code']);

        return $validated;
    }

    private function purchasingEnabled(): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && app(FeatureAccessService::class)->state($tenant, 'purchasing') === ModuleState::Enabled;
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
