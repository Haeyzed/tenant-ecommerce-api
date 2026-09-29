<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per employee per work date (spec §58.3, Assumption A-48). With a
 * rostered shift the work date is the shift's start date, so a night shift
 * stays one row; overtime is measured at clock-out (§58.3a).
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $work_date
 * @property int|null $shift_id
 * @property Carbon $clock_in_at
 * @property Carbon|null $clock_out_at
 * @property bool $is_late
 * @property bool $is_early_leave
 * @property int|null $scheduled_minutes
 * @property int|null $worked_minutes
 * @property int $overtime_minutes
 * @property string $overtime_status none | pending | approved | rejected
 * @property int|null $overtime_approved_minutes
 * @property int|null $overtime_decided_by_user_id
 * @property Carbon|null $overtime_decided_at
 * @property string|null $overtime_note
 * @property string|null $notes
 * @property-read HrEmployee $employee
 * @property-read HrShift|null $shift
 * @property-read User|null $overtimeDecidedBy
 */
class HrAttendance extends Model
{
    public const string OVERTIME_NONE = 'none';

    public const string OVERTIME_PENDING = 'pending';

    public const string OVERTIME_APPROVED = 'approved';

    public const string OVERTIME_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $table = 'hr_attendance';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'shift_id' => 'integer',
        'work_date' => 'date',
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'is_late' => 'boolean',
        'is_early_leave' => 'boolean',
        'scheduled_minutes' => 'integer',
        'worked_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'overtime_approved_minutes' => 'integer',
        'overtime_decided_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function overtimeDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overtime_decided_by_user_id');
    }
}
