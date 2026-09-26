<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One purchasable price of a plan (spec §11.6). amount, currency_code and
 * billing_interval are immutable once any subscription references the row.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $currency_code
 * @property string $billing_interval
 * @property string $amount
 * @property int|null $trial_days null = platform default_trial_days
 * @property bool $trial_requires_payment_method
 * @property bool $is_active
 * @property array<string, array<string, string>>|null $gateway_references keyed by mode, then provider
 * @property-read Plan $plan
 */
class PlanPrice extends Model implements Auditable
{
    use AuditsToLandlord;
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = [
        'plan_id', 'currency_code', 'billing_interval', 'amount', 'trial_days',
        'trial_requires_payment_method', 'is_active', 'gateway_references',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'trial_days' => 'integer',
        'trial_requires_payment_method' => 'boolean',
        'is_active' => 'boolean',
        'gateway_references' => 'array',
    ];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function gatewayReference(string $mode, string $provider): ?string
    {
        return $this->gateway_references[$mode][$provider] ?? null;
    }
}
