<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Spending that is not a purchase order (spec §57.4). pending → paid, one
 * way; a paid expense is never edited.
 *
 * @property int $id
 * @property int $expense_category_id
 * @property int|null $biller_id
 * @property int|null $supplier_id
 * @property string $amount
 * @property string $currency_code
 * @property string|null $exchange_rate_used
 * @property Carbon $expense_date
 * @property string|null $description
 * @property string $status pending | paid
 * @property Carbon|null $paid_at
 * @property int|null $paid_from_account_id
 * @property int $created_by_user_id
 * @property-read ExpenseCategory $category
 * @property-read Biller|null $biller
 */
class Expense extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;

    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    protected $connection = 'tenant';

    protected $fillable = ['expense_category_id', 'biller_id', 'supplier_id', 'amount', 'expense_date', 'description'];

    protected $casts = [
        'expense_category_id' => 'integer',
        'biller_id' => 'integer',
        'supplier_id' => 'integer',
        'amount' => 'decimal:4',
        'exchange_rate_used' => 'decimal:8',
        'expense_date' => 'date',
        'paid_at' => 'datetime',
        'paid_from_account_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipt')->singleFile()->useDisk('local');
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<Biller, $this>
     */
    public function biller(): BelongsTo
    {
        return $this->belongsTo(Biller::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
