<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An append-only change in a live subscription's normalised monthly
 * recurring amount (spec §14.1, §14.10). Never updated.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $subscription_id
 * @property int|null $plan_id the plan after the movement
 * @property string $type new | expansion | contraction | churn | reactivation
 * @property string $currency_code
 * @property string $mrr_before
 * @property string $mrr_after
 * @property string $mrr_delta
 * @property string $reason
 * @property Carbon $occurred_at
 */
class SubscriptionMrrMovement extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'subscription_id', 'plan_id', 'type', 'currency_code', 'mrr_before', 'mrr_after', 'mrr_delta', 'reason', 'occurred_at'];

    protected $casts = [
        'mrr_before' => 'decimal:4',
        'mrr_after' => 'decimal:4',
        'mrr_delta' => 'decimal:4',
        'occurred_at' => 'datetime',
    ];
}
