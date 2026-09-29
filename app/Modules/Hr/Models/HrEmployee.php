<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An employee (spec §58.2). A linked employee takes its name and email
 * from the staff user (§58.1); a standalone one keeps its own.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $department_id
 * @property string|null $employee_number
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $job_title
 * @property string $employment_type
 * @property Carbon $hire_date
 * @property Carbon|null $termination_date
 * @property string $status
 * @property-read User|null $user
 * @property-read HrDepartment|null $department
 */
class HrEmployee extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    public const string ACTIVE = 'active';

    public const string INACTIVE = 'inactive';

    public const array EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'internship'];

    protected $connection = 'tenant';

    protected $table = 'hr_employees';

    protected $fillable = [];

    protected $casts = [
        'user_id' => 'integer',
        'department_id' => 'integer',
        'hire_date' => 'date',
        'termination_date' => 'date',
    ];

    public function displayName(): string
    {
        return $this->user_id !== null
            ? (string) $this->user?->name
            : trim($this->first_name.' '.$this->last_name);
    }

    public function contactEmail(): ?string
    {
        return $this->user_id !== null ? $this->user?->email : $this->email;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<HrDepartment, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    /**
     * @return HasMany<HrEmployeeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(HrEmployeeDocument::class, 'employee_id');
    }
}
