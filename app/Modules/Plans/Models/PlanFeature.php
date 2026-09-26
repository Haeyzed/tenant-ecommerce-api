<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A feature key included in a plan (spec §11.7).
 *
 * @property int $id
 * @property int $plan_id
 * @property string $feature_key
 */
class PlanFeature extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['plan_id', 'feature_key'];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
