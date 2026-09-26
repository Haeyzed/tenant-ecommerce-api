<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A discount rule (spec §37.2): automatic or coupon-triggered, on lines,
 * the order or shipping. valid_days_of_week is stored as an ISO bitmask
 * (Mon=1 … Sun=64) and exposed as a list of ISO day numbers through
 * valid_days.
 *
 * @property int $id
 * @property string $name
 * @property string|null $public_label
 * @property string|null $description
 * @property string $trigger automatic | coupon
 * @property string $scope line | order | shipping
 * @property string $discount_type percentage | fixed_amount | free_shipping
 * @property string|null $discount_value
 * @property string|null $max_discount_amount
 * @property string|null $min_subtotal_amount
 * @property string|null $max_subtotal_amount
 * @property string|null $min_eligible_quantity
 * @property string|null $max_eligible_quantity
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $valid_days_of_week
 * @property list<int>|null $valid_days
 * @property string|null $valid_time_from
 * @property string|null $valid_time_to
 * @property bool $applies_to_online
 * @property bool $applies_to_pos
 * @property bool $applies_to_sale_items
 * @property bool $first_order_only
 * @property int|null $usage_limit_total
 * @property int|null $usage_limit_per_customer
 * @property int $times_redeemed
 * @property bool $is_exclusive
 * @property int $priority
 * @property int|null $seller_id
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property-read Collection<int, PromotionTarget> $targets
 */
class Promotion extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    public const string AUTOMATIC = 'automatic';

    public const string COUPON = 'coupon';

    public const array SCOPES = ['line', 'order', 'shipping'];

    public const string PERCENTAGE = 'percentage';

    public const string FIXED = 'fixed_amount';

    public const string FREE_SHIPPING = 'free_shipping';

    public const array DISCOUNT_TYPES = [self::PERCENTAGE, self::FIXED, self::FREE_SHIPPING];

    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'public_label', 'description', 'trigger', 'scope', 'discount_type', 'discount_value', 'max_discount_amount',
        'min_subtotal_amount', 'max_subtotal_amount', 'min_eligible_quantity', 'max_eligible_quantity', 'starts_at', 'ends_at',
        'valid_days', 'valid_time_from', 'valid_time_to', 'applies_to_online', 'applies_to_pos', 'applies_to_sale_items',
        'first_order_only', 'usage_limit_total', 'usage_limit_per_customer', 'is_exclusive', 'priority', 'seller_id', 'is_active',
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['times_redeemed'];

    protected $casts = [
        'discount_value' => 'decimal:4',
        'max_discount_amount' => 'decimal:4',
        'min_subtotal_amount' => 'decimal:4',
        'max_subtotal_amount' => 'decimal:4',
        'min_eligible_quantity' => 'decimal:3',
        'max_eligible_quantity' => 'decimal:3',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'valid_days_of_week' => 'integer',
        'applies_to_online' => 'boolean',
        'applies_to_pos' => 'boolean',
        'applies_to_sale_items' => 'boolean',
        'first_order_only' => 'boolean',
        'usage_limit_total' => 'integer',
        'usage_limit_per_customer' => 'integer',
        'times_redeemed' => 'integer',
        'is_exclusive' => 'boolean',
        'priority' => 'integer',
        'seller_id' => 'integer',
        'is_active' => 'boolean',
        'created_by_user_id' => 'integer',
    ];

    /**
     * ISO day numbers (1 = Monday … 7 = Sunday) over the bitmask.
     *
     * @return Attribute<list<int>|null, list<int>|null>
     */
    protected function validDays(): Attribute
    {
        return Attribute::make(
            get: fn (): ?array => $this->valid_days_of_week === null ? null
                : array_values(array_filter(range(1, 7), fn (int $day): bool => ($this->valid_days_of_week & (1 << ($day - 1))) !== 0)),
            set: static fn (?array $days): array => ['valid_days_of_week' => $days === null || $days === [] ? null
                : array_reduce(array_unique(array_map('intval', $days)), static fn (int $mask, int $day): int => $mask | (1 << ($day - 1)), 0)],
        );
    }

    public function label(): string
    {
        return $this->public_label ?? $this->name;
    }

    /**
     * @return HasMany<PromotionTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    /**
     * @return HasMany<Coupon, $this>
     */
    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    /**
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
