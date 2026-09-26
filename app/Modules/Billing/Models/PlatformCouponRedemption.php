<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One subscription's use of a platform coupon, with the terms snapshotted
 * at reservation (spec §14.8).
 *
 * @property int $id
 * @property int $platform_coupon_id
 * @property string $tenant_id
 * @property int $subscription_id
 * @property string $owner_email
 * @property string $code_snapshot
 * @property string $discount_type_snapshot
 * @property string $discount_value_snapshot
 * @property string|null $max_discount_snapshot
 * @property int $cycles_total
 * @property int $cycles_applied
 * @property string $total_discount_amount
 * @property string $status reserved | active | completed | released
 * @property Carbon $reserved_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $released_at
 */
class PlatformCouponRedemption extends Model
{
    public const string RESERVED = 'reserved';

    public const string ACTIVE = 'active';

    public const string COMPLETED = 'completed';

    public const string RELEASED = 'released';

    protected $connection = 'landlord';

    protected $fillable = [
        'platform_coupon_id', 'tenant_id', 'subscription_id', 'owner_email', 'code_snapshot', 'discount_type_snapshot',
        'discount_value_snapshot', 'max_discount_snapshot', 'cycles_total', 'cycles_applied', 'total_discount_amount',
        'status', 'reserved_at', 'activated_at', 'completed_at', 'released_at',
    ];

    protected $casts = [
        'discount_value_snapshot' => 'decimal:4',
        'max_discount_snapshot' => 'decimal:4',
        'total_discount_amount' => 'decimal:4',
        'cycles_total' => 'integer',
        'cycles_applied' => 'integer',
        'reserved_at' => 'datetime',
        'activated_at' => 'datetime',
        'completed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PlatformCoupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PlatformCoupon::class, 'platform_coupon_id');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::RESERVED, self::ACTIVE], true);
    }
}
