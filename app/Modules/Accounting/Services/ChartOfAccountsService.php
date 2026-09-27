<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountCategory;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The chart of accounts (spec §57.2, §57.7). System accounts and
 * categories are seeded (A-42); system accounts can be renamed but never
 * deactivated, and no account is deleted once a journal line uses it.
 */
final class ChartOfAccountsService
{
    /** Categories (§57.2), in display order. */
    public const array CATEGORIES = [
        'current_assets' => ['Current Assets', 'asset', 10],
        'fixed_assets' => ['Fixed Assets', 'asset', 20],
        'current_liabilities' => ['Current Liabilities', 'liability', 30],
        'long_term_liabilities' => ['Long-Term Liabilities', 'liability', 40],
        'equity' => ['Equity', 'equity', 50],
        'revenue' => ['Revenue', 'revenue', 60],
        'expenses' => ['Expenses', 'expense', 70],
    ];

    /** System accounts (§57.2): system_key => [code, name, category]. */
    public const array SYSTEM_ACCOUNTS = [
        'cash_bank' => ['1000', 'Cash/Bank', 'current_assets'],
        'accounts_receivable' => ['1100', 'Accounts Receivable', 'current_assets'],
        'inventory_asset' => ['1200', 'Inventory Asset', 'current_assets'],
        'accounts_payable' => ['2000', 'Accounts Payable (suppliers)', 'current_liabilities'],
        'accounts_payable_sellers' => ['2010', 'Accounts Payable – Sellers', 'current_liabilities'],
        'gift_card_liability' => ['2020', 'Gift Card Liability', 'current_liabilities'],
        'tax_payable' => ['2100', 'Tax Payable', 'current_liabilities'],
        'payroll_liabilities' => ['2200', 'Payroll Liabilities', 'current_liabilities'],
        'sales_revenue' => ['4000', 'Sales Revenue', 'revenue'],
        'shipping_revenue' => ['4010', 'Shipping Revenue', 'revenue'],
        'commission_revenue' => ['4020', 'Commission Revenue', 'revenue'],
        'sales_returns' => ['4100', 'Sales Returns and Allowances', 'revenue'],
        'other_income' => ['4900', 'Other Income', 'revenue'],
        'cost_of_goods_sold' => ['5000', 'Cost of Goods Sold', 'expenses'],
        'inventory_adjustments' => ['5010', 'Inventory Adjustments (shrinkage and write-offs)', 'expenses'],
        'payment_processing_fees' => ['5100', 'Payment Processing Fees', 'expenses'],
        'salaries_wages' => ['5200', 'Salaries and Wages Expense', 'expenses'],
        'general_expenses' => ['5900', 'General Expenses', 'expenses'],
    ];

    /**
     * Insert-only defaults for the tenant defaults sync (§12.6): a missing
     * category or system account is created; nothing existing is changed.
     */
    public function seedDefaults(): void
    {
        DB::connection('tenant')->transaction(static function (): void {
            $categories = [];

            foreach (self::CATEGORIES as $key => [$name, $type, $order]) {
                $category = AccountCategory::query()->where('is_system', true)->where('name', $name)->first();

                if ($category === null) {
                    $category = new AccountCategory(['name' => $name, 'account_type' => $type, 'sort_order' => $order]);
                    $category->forceFill(['is_system' => true])->save();
                }

                $categories[$key] = $category;
            }

            foreach (self::SYSTEM_ACCOUNTS as $systemKey => [$code, $name, $category]) {
                if (Account::query()->where('system_key', $systemKey)->exists()) {
                    continue;
                }

                // A tenant account already holding the code keeps it; the
                // system account takes the next free code in its block.
                while (Account::query()->where('code', $code)->exists()) {
                    $code = (string) ((int) $code + 1);
                }

                $account = new Account(['code' => $code, 'name' => $name, 'account_category_id' => $categories[$category]->id]);
                $account->forceFill(['system_key' => $systemKey, 'is_system' => true, 'is_active' => true])->save();
            }
        });
    }

    /**
     * @param  array{account_type?: string, category_id?: int, is_active?: bool, search?: string}  $filters
     * @return Collection<int, Account>
     */
    public function listAccounts(array $filters = []): Collection
    {
        return Account::query()->with('category:id,name,account_type')
            ->when(isset($filters['category_id']), static fn ($q) => $q->where('account_category_id', $filters['category_id']))
            ->when(isset($filters['account_type']), static fn ($q) => $q->whereHas('category', static fn ($c) => $c->where('account_type', $filters['account_type'])))
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['search']), static fn ($q) => $q->where(static fn ($w) => $w->where('name', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%')->orWhere('code', $filters['search'])))
            ->orderBy('code')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAccount(array $data): Account
    {
        $validated = validator($data, [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('tenant.chart_of_accounts', 'code')],
            'name' => ['required', 'string', 'max:160'],
            'account_category_id' => ['required', 'integer', Rule::exists('tenant.account_categories', 'id')],
            'parent_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.chart_of_accounts', 'id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        $this->assertParent($validated['parent_account_id'] ?? null, (int) $validated['account_category_id']);

        $account = new Account($validated);
        $account->forceFill(['is_system' => false, 'is_active' => true])->save();

        return $account->load('category');
    }

    /**
     * System accounts allow name and description only (§57.7).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(Account $account, array $data): Account
    {
        $allowed = $account->is_system ? ['name', 'description'] : ['code', 'name', 'account_category_id', 'parent_account_id', 'description'];

        if ($account->is_system && array_diff(array_keys($data), $allowed) !== []) {
            throw ApiException::unprocessable('system_account', 'A system account can only be renamed or described.');
        }

        $validated = validator(array_intersect_key($data, array_flip($allowed)), [
            'code' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('tenant.chart_of_accounts', 'code')->ignore($account->id)],
            'name' => ['sometimes', 'string', 'max:160'],
            'account_category_id' => ['sometimes', 'integer', Rule::exists('tenant.account_categories', 'id')],
            'parent_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.chart_of_accounts', 'id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        $categoryId = (int) ($validated['account_category_id'] ?? $account->account_category_id);
        $this->assertParent(array_key_exists('parent_account_id', $validated) ? $validated['parent_account_id'] : $account->parent_account_id, $categoryId, $account);

        if (isset($validated['account_category_id']) && $validated['account_category_id'] !== $account->account_category_id && $this->used($account)) {
            $from = $account->category->account_type;
            $to = AccountCategory::query()->whereKey($categoryId)->value('account_type');

            if ($from !== $to) {
                throw ApiException::unprocessable('account_in_use', 'An account with postings cannot move to a category of another type.');
            }
        }

        $account->fill($validated)->save();

        return $account->load('category');
    }

    /**
     * Blocked for system accounts and for accounts an active expense or
     * income category posts to (§57.7).
     */
    public function deactivateAccount(Account $account): Account
    {
        if ($account->is_system) {
            throw ApiException::unprocessable('system_account', 'System accounts cannot be deactivated.');
        }

        foreach (['expense_categories', 'income_categories'] as $table) {
            if (DB::connection('tenant')->table($table)->where('account_id', $account->id)->where('is_active', true)->exists()) {
                throw ApiException::unprocessable('account_in_use', 'An active expense or income category posts to this account.');
            }
        }

        $account->forceFill(['is_active' => false])->save();

        return $account;
    }

    /**
     * @return Collection<int, AccountCategory>
     */
    public function listCategories(): Collection
    {
        return AccountCategory::query()->withCount('accounts')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): AccountCategory
    {
        $validated = validator($data, [
            'name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', Rule::in(AccountCategory::TYPES)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ])->validate();

        return AccountCategory::query()->create($validated);
    }

    /**
     * The type of a category never changes: accounts read their type
     * through it.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(AccountCategory $category, array $data): AccountCategory
    {
        if (array_key_exists('account_type', $data) && $data['account_type'] !== $category->account_type) {
            throw ApiException::unprocessable('category_type_fixed', 'A category\'s account type cannot change.');
        }

        $validated = validator($data, [
            'name' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ])->validate();

        $category->fill($validated)->save();

        return $category;
    }

    private function used(Account $account): bool
    {
        return DB::connection('tenant')->table('journal_entry_lines')->where('account_id', $account->id)->exists();
    }

    /**
     * A parent is of the same account type, and never the account itself
     * or one of its descendants.
     */
    private function assertParent(mixed $parentId, int $categoryId, ?Account $account = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Account::query()->with('category')->findOrFail((int) $parentId);
        $type = AccountCategory::query()->whereKey($categoryId)->value('account_type');

        if ($parent->category->account_type !== $type) {
            throw ApiException::unprocessable('parent_type_mismatch', 'A sub-account must be of its parent\'s account type.');
        }

        for ($cursor = $parent; $cursor !== null; $cursor = $cursor->parent) {
            if ($account !== null && $cursor->id === $account->id) {
                throw ApiException::unprocessable('parent_cycle', 'An account cannot sit under itself.');
            }
        }
    }
}
