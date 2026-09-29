<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per employee per day (spec §58.3, Assumption A-48).
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $work_date
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property bool $is_late
 * @property bool $is_early_leave
 * @property string|null $notes
 * @property-read HrEmployee $employee
 */
class HrAttendance extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_attendance';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'work_date' => 'date',
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'is_late' => 'boolean',
        'is_early_leave' => 'boolean',
    ];

    /**
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id')->withTrashed();
    }
}
