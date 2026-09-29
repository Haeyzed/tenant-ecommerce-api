<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's entitlement of one leave type for one year (spec §58.4),
 * created on first access (Assumption A-49).
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $year
 * @property string $entitled_days
 * @property string $used_days
 * @property-read HrLeaveType $leaveType
 */
class HrLeaveBalance extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_leave_balances';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'leave_type_id' => 'integer',
        'year' => 'integer',
        'entitled_days' => 'decimal:1',
        'used_days' => 'decimal:1',
    ];

    public function remaining(): string
    {
        return bcsub((string) $this->entitled_days, (string) $this->used_days, 1);
    }

    /**
     * @return BelongsTo<HrLeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }
}
