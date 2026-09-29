<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A salary, effective for a period (spec §58.5). Never edited: a new
 * structure closes the previous one.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $base_salary
 * @property string $currency_code
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property-read HrEmployee $employee
 */
class HrSalaryStructure extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_salary_structures';

    protected $fillable = [];

    protected $casts = [
        'employee_id' => 'integer',
        'base_salary' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    /**
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id')->withTrashed();
    }
}
