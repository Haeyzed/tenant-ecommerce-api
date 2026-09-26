<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A discount on what a tenant pays the platform (spec §14.8). Unrelated to
 * tenant store coupons (§37): separate database, table and code path.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $discount_type percentage | fixed_amount
 * @property string $discount_value
 * @property string|null $currency_code
 * @property string $duration once | repeating
 * @property int|null $duration_cycles
 * @property string|null $max_discount_amount
 * @property string|null $min_amount
 * @property bool $first_subscription_only
 * @property int|null $usage_limit_total
 * @property int $usage_limit_per_tenant
 * @property int $times_redeemed
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $affiliate_id
 * @property bool $is_active
 * @property int $created_by
 */
class PlatformCoupon extends Model implements Auditable
{
    use AuditsToLandlord;

    public const string PERCENTAGE = 'percentage';

    public const string FIXED_AMOUNT = 'fixed_amount';

    protected $connection = 'landlord';

    protected $fillable = [
        'code', 'name', 'description', 'discount_type', 'discount_value', 'currency_code', 'duration', 'duration_cycles',
        'max_discount_amount', 'min_amount', 'first_subscription_only', 'usage_limit_total', 'usage_limit_per_tenant',
        'starts_at', 'ends_at', 'affiliate_id', 'is_active', 'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:4',
        'max_discount_amount' => 'decimal:4',
        'min_amount' => 'decimal:4',
        'duration_cycles' => 'integer',
        'first_subscription_only' => 'boolean',
        'usage_limit_total' => 'integer',
        'usage_limit_per_tenant' => 'integer',
        'times_redeemed' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany<PlatformCouponTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PlatformCouponTarget::class);
    }

    /**
     * @return HasMany<PlatformCouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PlatformCouponRedemption::class);
    }

    public function cycles(): int
    {
        return $this->duration === 'repeating' ? (int) $this->duration_cycles : 1;
    }
}
