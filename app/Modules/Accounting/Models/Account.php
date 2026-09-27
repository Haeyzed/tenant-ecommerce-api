<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An account of the chart of accounts (spec §57.2). Its type is read
 * through its category. System accounts carry a stable system_key used by
 * automatic postings; they can be renamed but never deleted or deactivated.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $account_category_id
 * @property int|null $parent_account_id
 * @property string|null $system_key
 * @property bool $is_system
 * @property bool $is_active
 * @property string|null $description
 * @property-read AccountCategory $category
 * @property-read Account|null $parent
 */
class Account extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $table = 'chart_of_accounts';

    protected $fillable = ['code', 'name', 'account_category_id', 'parent_account_id', 'description'];

    protected $casts = [
        'account_category_id' => 'integer',
        'parent_account_id' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function isDebitNormal(): bool
    {
        return in_array($this->category->account_type, AccountCategory::DEBIT_NORMAL, true)
            || $this->system_key === 'sales_returns';
    }

    /**
     * @return BelongsTo<AccountCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AccountCategory::class, 'account_category_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }
}
