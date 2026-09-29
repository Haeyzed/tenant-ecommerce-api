<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An itemised payslip line (spec §58.5). A percentage line's amount is
 * computed from the item's base salary when it is added.
 *
 * @property int $id
 * @property int $payroll_item_id
 * @property string $type
 * @property string $label
 * @property string $amount
 * @property bool $is_percentage
 * @property string|null $percentage
 * @property-read HrPayrollItem $item
 */
class HrPayrollItemLine extends Model
{
    public const array TYPES = ['allowance', 'deduction', 'tax', 'bonus', 'reimbursement'];

    /** Lines that add to gross pay. */
    public const array EARNINGS = ['allowance', 'bonus', 'reimbursement'];

    protected $connection = 'tenant';

    protected $table = 'hr_payroll_item_lines';

    protected $fillable = [];

    protected $casts = [
        'payroll_item_id' => 'integer',
        'amount' => 'decimal:4',
        'is_percentage' => 'boolean',
        'percentage' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<HrPayrollItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(HrPayrollItem::class, 'payroll_item_id');
    }
}
