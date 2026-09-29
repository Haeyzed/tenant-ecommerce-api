<?php

declare(strict_types=1);

namespace App\Modules\Repair\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A labour charge on a repair (spec §67.3), in the base currency; not
 * taxed (A-58).
 *
 * @property int $id
 * @property int $repair_job_id
 * @property string $description
 * @property string $amount
 */
class RepairJobLabor extends Model
{
    protected $table = 'repair_job_labor';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['repair_job_id' => 'integer', 'amount' => 'decimal:4'];
}
