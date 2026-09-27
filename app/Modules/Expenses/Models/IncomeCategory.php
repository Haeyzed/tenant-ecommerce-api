<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An income category (spec §57.4); its account (revenue type) receives the
 * posting, else other_income.
 *
 * @property int $id
 * @property string $name
 * @property int|null $account_id
 * @property bool $is_active
 * @property-read Account|null $account
 */
class IncomeCategory extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['name', 'account_id'];

    protected $casts = ['account_id' => 'integer', 'is_active' => 'boolean'];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
