<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A tenant's subscription to the platform (spec §14.1). A tenant has at most
 * one subscription whose status is not "cancelled".
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $plan_id
 * @property int $plan_price_id
 * @property string $currency_code
 * @property string $billing_interval
 * @property string|null $gateway
 * @property string $gateway_mode
 * @property string|null $gateway_plan_reference
 * @property string|null $gateway_subscription_reference
 * @property string|null $authorization_reference
 * @property SubscriptionStatus $status
 * @property int $trial_days
 * @property Carbon|null $trial_ends_at
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $renews_at
 * @property int|null $scheduled_plan_id
 * @property int|null $scheduled_plan_price_id
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $past_due_at
 * @property-read Plan $plan
 * @property-read PlanPrice $planPrice
 * @property-read Tenant $tenant
 */
class Subscription extends Model implements Auditable
{
    use AuditsToLandlord;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'plan_id', 'plan_price_id', 'currency_code', 'billing_interval', 'gateway', 'gateway_mode',
        'gateway_plan_reference', 'gateway_subscription_reference', 'authorization_reference', 'status',
        'trial_days', 'trial_ends_at', 'starts_at', 'ends_at', 'renews_at', 'scheduled_plan_id',
        'scheduled_plan_price_id', 'cancelled_at', 'past_due_at',
    ];

    protected $hidden = ['authorization_reference'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['authorization_reference'];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'authorization_reference' => 'encrypted',
        'trial_days' => 'integer',
        'trial_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'renews_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'past_due_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<PlanPrice, $this>
     */
    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isLive(): bool
    {
        return $this->gateway_mode === 'live';
    }

    /**
     * The subscription whose plan and status govern the tenant now: the
     * latest one that is not "incomplete", else the latest. A checkout
     * started for a new plan (incomplete) never displaces the plan the
     * tenant already has.
     */
    public static function governing(string $tenantId): ?self
    {
        /** @var self|null $subscription */
        $subscription = self::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', SubscriptionStatus::Incomplete->value)
            ->orderByDesc('id')
            ->first()
            ?? self::query()->where('tenant_id', $tenantId)->orderByDesc('id')->first();

        return $subscription;
    }

    /**
     * The one subscription that is not cancelled (spec §14.1).
     */
    public static function current(string $tenantId): ?self
    {
        /** @var self|null $subscription */
        $subscription = self::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', SubscriptionStatus::Cancelled->value)
            ->orderByDesc('id')
            ->first();

        return $subscription;
    }

    public function intervalMonths(): int
    {
        return $this->billing_interval === 'yearly' ? 12 : 1;
    }
}
