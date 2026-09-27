<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Expenses\Models\IncomeCategory;
use App\Modules\Expenses\Models\IncomeEntry;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Other income (spec §57.4, §57.7): the same shape as expenses. markReceived()
 * is one-way and records the postIncome request.
 */
final readonly class IncomeService
{
    public function __construct(
        private AccountingOutbox $outbox,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @return Collection<int, IncomeCategory>
     */
    public function listCategories(): Collection
    {
        return IncomeCategory::query()->with('account:id,code,name')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data  name, account_id (an active revenue account), is_active
     */
    public function createCategory(array $data): IncomeCategory
    {
        $validated = $this->validateCategory($data, true);
        $category = new IncomeCategory($validated);
        $category->forceFill(['is_active' => (bool) ($validated['is_active'] ?? true)])->save();

        return $category->load('account:id,code,name');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(IncomeCategory $category, array $data): IncomeCategory
    {
        $validated = $this->validateCategory($data, false);
        $category->fill($validated);

        if (array_key_exists('is_active', $validated)) {
            $category->forceFill(['is_active' => (bool) $validated['is_active']]);
        }

        $category->save();

        return $category->load('account:id,code,name');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createIncome(array $data, User $by): IncomeEntry
    {
        $validated = $this->validateIncome($data, true);
        $income = new IncomeEntry($validated);
        $income->forceFill([
            'amount' => Money::round(Money::normalize((string) $validated['amount']), $this->currency()),
            'currency_code' => $this->currency(),
            'status' => IncomeEntry::PENDING,
            'created_by_user_id' => $by->id,
        ])->save();

        return $income->load('category:id,name');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateIncome(IncomeEntry $income, array $data): IncomeEntry
    {
        $this->assertPending($income);
        $validated = $this->validateIncome($data, false);

        if (isset($validated['amount'])) {
            $validated['amount'] = Money::round(Money::normalize((string) $validated['amount']), $this->currency());
        }

        $income->fill($validated)->save();

        return $income->load('category:id,name');
    }

    public function deleteIncome(IncomeEntry $income): void
    {
        $this->assertPending($income);
        $income->delete();
    }

    /**
     * One-way (§57.4); posts Dr the received-into account, Cr income.
     */
    public function markReceived(IncomeEntry $income, ?Account $intoAccount = null): IncomeEntry
    {
        if ($intoAccount !== null) {
            $intoAccount->loadMissing('category');

            if (! $intoAccount->is_active || $intoAccount->category->account_type !== 'asset') {
                throw ApiException::unprocessable('account_invalid', 'Receive into an active asset account (cash or bank).');
            }
        }

        DB::connection('tenant')->transaction(function () use ($income, $intoAccount): void {
            /** @var IncomeEntry $locked */
            $locked = IncomeEntry::query()->lockForUpdate()->findOrFail($income->id);
            $this->assertPending($locked);

            $locked->forceFill(['status' => IncomeEntry::RECEIVED, 'received_at' => now(), 'received_into_account_id' => $intoAccount?->id])->save();
            $this->outbox->record('postIncome', $locked, $locked->received_at, 'income:'.$locked->id);
            $income->setRawAttributes($locked->getAttributes(), true);
        });

        return $income->load('category:id,name');
    }

    /**
     * @param  array{status?: string, income_category_id?: int, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, IncomeEntry>
     */
    public function listIncome(array $filters = []): LengthAwarePaginator
    {
        return IncomeEntry::query()->with(['category:id,name', 'creator:id,name'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['income_category_id']), static fn ($q) => $q->where('income_category_id', $filters['income_category_id']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('received_date', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('received_date', '<=', $filters['to']))
            ->orderByDesc('received_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function assertPending(IncomeEntry $income): void
    {
        if ($income->status !== IncomeEntry::PENDING) {
            throw ApiException::unprocessable('income_received', 'Received income cannot be changed. Reverse its journal entry and record a new entry.');
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
                ->whereIn('account_category_id', DB::connection('tenant')->table('account_categories')->where('account_type', 'revenue')->pluck('id')->all())],
            'is_active' => ['sometimes', 'boolean'],
        ], ['account_id.exists' => 'Choose an active revenue account.'])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateIncome(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $validated = validator($data, [
            'income_category_id' => [$req, 'integer', Rule::exists('tenant.income_categories', 'id')->where('is_active', true)],
            'source' => ['sometimes', 'nullable', 'string', 'max:160'],
            'amount' => [$req, 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999999'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'received_date' => [$req, 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        if (isset($validated['currency_code']) && strtoupper((string) $validated['currency_code']) !== $this->currency()) {
            throw ApiException::unprocessable('currency_not_supported', 'Income is recorded in '.$this->currency().'.');
        }

        unset($validated['currency_code']);

        return $validated;
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
