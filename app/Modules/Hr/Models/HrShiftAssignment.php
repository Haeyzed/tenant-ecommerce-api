<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rostered shift: an employee works a shift starting on a date
 * (spec §58.3a). At most one per employee per date.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $shift_id
 * @property Carbon $work_date
 * @property string|null $notes
 * @property int|null $assigned_by_user_id
 * @property-read HrEmployee $employee
 * @property-read HrShift $shift
 */
class HrShiftAssignment extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_shift_assignments';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'shift_id' => 'integer',
        'work_date' => 'date',
    ];

    /**
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id')->withTrashed();
    }

    /**
     * @return BelongsTo<HrShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }
}
