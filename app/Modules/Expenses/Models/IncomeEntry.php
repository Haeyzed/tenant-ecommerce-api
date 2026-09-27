<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Money received that is not a customer order (spec §57.4). pending →
 * received, one way.
 *
 * @property int $id
 * @property int $income_category_id
 * @property string|null $source
 * @property string $amount
 * @property string $currency_code
 * @property string|null $exchange_rate_used
 * @property Carbon $received_date
 * @property string|null $description
 * @property string $status pending | received
 * @property Carbon|null $received_at
 * @property int|null $received_into_account_id
 * @property int $created_by_user_id
 * @property-read IncomeCategory $category
 */
class IncomeEntry extends Model implements AuditableContract
{
    use Auditable;

    public const string PENDING = 'pending';

    public const string RECEIVED = 'received';

    protected $connection = 'tenant';

    protected $table = 'income_entries';

    protected $fillable = ['income_category_id', 'source', 'amount', 'received_date', 'description'];

    protected $casts = [
        'income_category_id' => 'integer',
        'amount' => 'decimal:4',
        'exchange_rate_used' => 'decimal:12',
        'received_date' => 'date',
        'received_at' => 'datetime',
        'received_into_account_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    /**
     * @return BelongsTo<IncomeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class, 'income_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
