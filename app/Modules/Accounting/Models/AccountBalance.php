<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The balance read cache of one account in one period (spec §57.3). Never
 * the source of truth: rebuildable from journal_entry_lines.
 *
 * @property int $id
 * @property int $account_id
 * @property int $fiscal_period_id
 * @property string $opening_balance
 * @property string $debit_total
 * @property string $credit_total
 * @property string $closing_balance
 */
class AccountBalance extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'account_id' => 'integer',
        'fiscal_period_id' => 'integer',
        'opening_balance' => 'decimal:4',
        'debit_total' => 'decimal:4',
        'credit_total' => 'decimal:4',
        'closing_balance' => 'decimal:4',
    ];
}
