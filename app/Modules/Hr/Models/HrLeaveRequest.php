<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $days
 * @property string|null $reason
 * @property string $status
 * @property string|null $rejection_reason
 * @property int|null $requested_by_user_id
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property-read HrEmployee $employee
 * @property-read HrLeaveType $leaveType
 * @property-read User|null $decidedBy
 */
class HrLeaveRequest extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $table = 'hr_leave_requests';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'leave_type_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'days' => 'decimal:1',
        'requested_by_user_id' => 'integer',
        'decided_by_user_id' => 'integer',
        'decided_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id')->withTrashed();
    }

    /**
     * @return BelongsTo<HrLeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
