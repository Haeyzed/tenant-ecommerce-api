<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's value for one limit key (spec §11.8). null = unlimited.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $limit_key
 * @property int|null $limit_value
 */
class PlanLimit extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['plan_id', 'limit_key', 'limit_value'];

    protected $casts = ['limit_value' => 'integer'];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
