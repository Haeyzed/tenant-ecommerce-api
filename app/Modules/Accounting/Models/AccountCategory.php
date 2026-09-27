<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grouping of accounts under one account type (spec §57.2).
 *
 * @property int $id
 * @property string $name
 * @property string $account_type asset | liability | equity | revenue | expense
 * @property bool $is_system
 * @property int $sort_order
 */
class AccountCategory extends Model
{
    public const array TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Debit-normal types; the rest are credit-normal (§57.2). */
    public const array DEBIT_NORMAL = ['asset', 'expense'];

    protected $connection = 'tenant';

    protected $fillable = ['name', 'account_type', 'sort_order'];

    protected $casts = ['is_system' => 'boolean', 'sort_order' => 'integer'];

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
