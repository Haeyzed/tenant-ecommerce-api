<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $days_per_year
 * @property bool $is_paid
 * @property bool $is_active
 */
class HrLeaveType extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_leave_types';

    protected $fillable = [];

    protected $casts = [
        'days_per_year' => 'decimal:1',
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
    ];
}
