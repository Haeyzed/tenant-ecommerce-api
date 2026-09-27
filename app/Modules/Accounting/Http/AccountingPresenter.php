<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountCategory;
use App\Modules\Accounting\Models\AccountingPostingRequest;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;

/**
 * Response shapes of the Accounting module (spec §57).
 */
final class AccountingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function account(Account $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'category' => $a->relationLoaded('category') ? ['id' => $a->category->id, 'name' => $a->category->name, 'account_type' => $a->category->account_type] : ['id' => $a->account_category_id],
            'parent_account_id' => $a->parent_account_id,
            'system_key' => $a->system_key,
            'is_system' => $a->is_system,
            'is_active' => $a->is_active,
            'description' => $a->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function category(AccountCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'account_type' => $c->account_type,
            'is_system' => $c->is_system,
            'sort_order' => $c->sort_order,
            'accounts_count' => $c->getAttributes()['accounts_count'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(JournalEntry $e, bool $withLines = true): array
    {
        $data = [
            'id' => $e->id,
            'entry_date' => $e->entry_date->toDateString(),
            'fiscal_period_id' => $e->fiscal_period_id,
            'description' => $e->description,
            'source' => $e->source,
            'reference_type' => $e->reference_type,
            'reference_id' => $e->reference_id,
            'posting_key' => $e->posting_key,
            'cash_flow_category' => $e->cash_flow_category,
            'reverses_journal_entry_id' => $e->reverses_journal_entry_id,
            'reversed_at' => $e->reversed_at?->toIso8601String(),
            'created_by' => $e->relationLoaded('creator') && $e->creator !== null ? ['id' => $e->creator->id, 'name' => $e->creator->name] : null,
            'created_at' => $e->created_at?->toIso8601String(),
        ];

        if ($withLines) {
            $data['lines'] = $e->lines->map(static fn (JournalEntryLine $l): array => [
                'id' => $l->id,
                'account' => $l->relationLoaded('account') ? ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name] : ['id' => $l->account_id],
                'type' => $l->type,
                'amount' => (string) $l->amount,
                'description' => $l->description,
            ])->values()->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function year(FiscalYear $y): array
    {
        return [
            'id' => $y->id,
            'name' => $y->name,
            'starts_on' => $y->starts_on->toDateString(),
            'ends_on' => $y->ends_on->toDateString(),
            'status' => $y->status,
            'closed_at' => $y->closed_at?->toIso8601String(),
            'periods_count' => $y->getAttributes()['periods_count'] ?? ($y->relationLoaded('periods') ? $y->periods->count() : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function period(FiscalPeriod $p): array
    {
        return [
            'id' => $p->id,
            'fiscal_year_id' => $p->fiscal_year_id,
            'name' => $p->name,
            'starts_on' => $p->starts_on->toDateString(),
            'ends_on' => $p->ends_on->toDateString(),
            'status' => $p->status,
            'closed_at' => $p->closed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(AccountingPostingRequest $r): array
    {
        return [
            'id' => $r->id,
            'posting_key' => $r->posting_key,
            'method' => $r->method,
            'reference_type' => $r->reference_type,
            'reference_id' => $r->reference_id,
            'event_date' => $r->event_date->toDateString(),
            'status' => $r->status,
            'attempts' => $r->attempts,
            'last_error' => $r->last_error,
            'journal_entry_id' => $r->journal_entry_id,
            'processed_at' => $r->processed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
