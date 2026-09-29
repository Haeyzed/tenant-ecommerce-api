<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HR's own settings (spec §58.3): the tenant-wide row (department_id null)
 * and optional per-department overrides.
 *
 * @property int $id
 * @property int|null $department_id
 * @property string $expected_clock_in_time
 * @property string $expected_clock_out_time
 * @property int $late_grace_minutes
 * @property int $early_leave_grace_minutes
 */
class HrSettings extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_settings';

    protected $fillable = [];

    protected $hidden = ['scope_key'];

    protected $casts = [
        'department_id' => 'integer',
        'late_grace_minutes' => 'integer',
        'early_leave_grace_minutes' => 'integer',
    ];
}
