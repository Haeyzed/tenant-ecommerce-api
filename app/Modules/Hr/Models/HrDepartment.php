<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 */
class HrDepartment extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_departments';

    protected $fillable = [];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * @return HasMany<HrEmployee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployee::class, 'department_id');
    }
}
