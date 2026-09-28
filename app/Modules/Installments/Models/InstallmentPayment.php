<?php

declare(strict_types=1);

namespace App\Modules\Installments\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled payment of a plan (spec §47.1).
 *
 * @property int $id
 * @property int $installment_plan_id
 * @property int $sequence
 * @property string $amount_due
 * @property Carbon $due_date
 * @property string $amount_paid
 * @property Carbon|null $paid_at
 * @property string $status pending | paid | overdue | failed
 * @property-read InstallmentPlan $plan
 */
class InstallmentPayment extends Model
{
    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    public const string OVERDUE = 'overdue';

    public const string FAILED = 'failed';

    /** Still to be paid. */
    public const array OPEN = [self::PENDING, self::OVERDUE, self::FAILED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'installment_plan_id' => 'integer',
        'sequence' => 'integer',
        'amount_due' => 'decimal:4',
        'due_date' => 'date',
        'amount_paid' => 'decimal:4',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<InstallmentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }
}
