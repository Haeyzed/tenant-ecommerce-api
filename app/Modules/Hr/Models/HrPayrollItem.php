<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's pay in a run (spec §58.5): the payslip.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property string $base_salary
 * @property string $total_allowances
 * @property string $gross_pay
 * @property string $total_deductions
 * @property string $tax_amount
 * @property string $net_pay
 * @property string $status
 * @property Carbon|null $paid_at
 * @property-read HrPayrollRun $run
 * @property-read HrEmployee $employee
 * @property-read Collection<int, HrPayrollItemLine> $lines
 */
class HrPayrollItem extends Model
{
    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    protected $connection = 'tenant';

    protected $table = 'hr_payroll_items';

    protected $fillable = [];

    protected $casts = [
        'payroll_run_id' => 'integer',
        'employee_id' => 'integer',
        'base_salary' => 'decimal:4',
        'total_allowances' => 'decimal:4',
        'gross_pay' => 'decimal:4',
        'total_deductions' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'net_pay' => 'decimal:4',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<HrPayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id')->withTrashed();
    }

    /**
     * @return HasMany<HrPayrollItemLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(HrPayrollItemLine::class, 'payroll_item_id')->orderBy('id');
    }
}
