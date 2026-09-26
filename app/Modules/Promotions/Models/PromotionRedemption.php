<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One promotion applied to one order, with a snapshot of the rule (spec
 * §37.5). Written by the redemption lifecycle with orders.
 *
 * @property int $id
 * @property int $promotion_id
 * @property int|null $coupon_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string|null $customer_email
 * @property string $promotion_name_snapshot
 * @property string|null $public_label_snapshot
 * @property string|null $coupon_code_snapshot
 * @property string $scope_snapshot
 * @property string $discount_type_snapshot
 * @property string|null $discount_value_snapshot
 * @property string $discount_amount
 * @property string $base_discount_amount
 * @property int|null $seller_id
 * @property string $status reserved | committed | released | reversed
 * @property bool $over_limit
 * @property Carbon|null $committed_at
 * @property Carbon|null $released_at
 * @property Carbon $created_at
 */
class PromotionRedemption extends Model
{
    public const string RESERVED = 'reserved';

    public const string COMMITTED = 'committed';

    public const string RELEASED = 'released';

    public const string REVERSED = 'reversed';

    /** Statuses that consume usage. */
    public const array COUNTING = [self::RESERVED, self::COMMITTED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'promotion_id' => 'integer',
        'coupon_id' => 'integer',
        'order_id' => 'integer',
        'customer_id' => 'integer',
        'discount_value_snapshot' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'base_discount_amount' => 'decimal:4',
        'seller_id' => 'integer',
        'over_limit' => 'boolean',
        'committed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }
}
