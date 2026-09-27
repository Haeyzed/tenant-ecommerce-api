<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http;

use App\Modules\Expenses\Models\Biller;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Models\IncomeCategory;
use App\Modules\Expenses\Models\IncomeEntry;

/**
 * Response shapes of the Expenses module (spec §57.4).
 */
final class ExpensePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function biller(Biller $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'phone' => $b->phone,
            'email' => $b->email,
            'category' => $b->category,
            'account_reference' => $b->account_reference,
            'notes' => $b->notes,
            'is_active' => $b->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function category(ExpenseCategory|IncomeCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'account' => $c->account === null ? null : ['id' => $c->account->id, 'code' => $c->account->code, 'name' => $c->account->name],
            'is_active' => $c->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function expense(Expense $e): array
    {
        $receipt = $e->getFirstMedia('receipt');

        return [
            'id' => $e->id,
            'category' => $e->relationLoaded('category') ? ['id' => $e->category->id, 'name' => $e->category->name] : ['id' => $e->expense_category_id],
            'biller' => $e->relationLoaded('biller') && $e->biller !== null ? ['id' => $e->biller->id, 'name' => $e->biller->name] : null,
            'supplier_id' => $e->supplier_id,
            'amount' => (string) $e->amount,
            'currency_code' => $e->currency_code,
            'expense_date' => $e->expense_date->toDateString(),
            'description' => $e->description,
            'status' => $e->status,
            'paid_at' => $e->paid_at?->toIso8601String(),
            'paid_from_account_id' => $e->paid_from_account_id,
            'has_receipt' => $receipt !== null,
            'receipt_name' => $receipt?->name,
            'created_by' => $e->relationLoaded('creator') && $e->creator !== null ? ['id' => $e->creator->id, 'name' => $e->creator->name] : null,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function income(IncomeEntry $i): array
    {
        return [
            'id' => $i->id,
            'category' => $i->relationLoaded('category') ? ['id' => $i->category->id, 'name' => $i->category->name] : ['id' => $i->income_category_id],
            'source' => $i->source,
            'amount' => (string) $i->amount,
            'currency_code' => $i->currency_code,
            'received_date' => $i->received_date->toDateString(),
            'description' => $i->description,
            'status' => $i->status,
            'received_at' => $i->received_at?->toIso8601String(),
            'received_into_account_id' => $i->received_into_account_id,
            'created_by' => $i->relationLoaded('creator') && $i->creator !== null ? ['id' => $i->creator->id, 'name' => $i->creator->name] : null,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
