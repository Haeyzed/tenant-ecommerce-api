<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A payroll run (spec §58.5): draft → processing → finalized → paid. Once
 * finalized its items and lines never change (§5.2).
 *
 * @property int $id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property Carbon $run_date
 * @property string $total_gross
 * @property string $total_deductions
 * @property string $total_net
 * @property Carbon|null $finalized_at
 * @property int|null $finalized_by_user_id
 * @property Carbon|null $paid_at
 * @property int|null $created_by_user_id
 * @property-read Collection<int, HrPayrollItem> $items
 */
class HrPayrollRun extends Model implements AuditableContract
{
    use Auditable;

    public const string DRAFT = 'draft';

    public const string PROCESSING = 'processing';

    public const string FINALIZED = 'finalized';

    public const string PAID = 'paid';

    public const array STATUSES = [self::DRAFT, self::PROCESSING, self::FINALIZED, self::PAID];

    protected $connection = 'tenant';

    protected $table = 'hr_payroll_runs';

    protected $fillable = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'run_date' => 'date',
        'total_gross' => 'decimal:4',
        'total_deductions' => 'decimal:4',
        'total_net' => 'decimal:4',
        'finalized_at' => 'datetime',
        'finalized_by_user_id' => 'integer',
        'paid_at' => 'datetime',
        'created_by_user_id' => 'integer',
    ];

    public function isLocked(): bool
    {
        return in_array($this->status, [self::FINALIZED, self::PAID], true);
    }

    /**
     * @return HasMany<HrPayrollItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(HrPayrollItem::class, 'payroll_run_id')->orderBy('id');
    }
}
