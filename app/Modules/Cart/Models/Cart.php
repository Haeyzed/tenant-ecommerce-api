<?php

declare(strict_types=1);

namespace App\Modules\Cart\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Promotions\Models\Coupon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shopper's cart (spec §38.1): a customer's, or a guest's identified by
 * an opaque token. Exactly one of the two is set.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $guest_token
 * @property string $currency_code
 * @property int|null $coupon_id
 * @property int|null $gift_card_id
 * @property int|null $reward_points_to_redeem
 * @property Carbon $last_activity_at
 * @property-read Customer|null $customer
 * @property-read Coupon|null $coupon
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    public const int MAX_LINES = 100;

    public const int GUEST_TTL_DAYS = 30;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'customer_id' => 'integer',
        'coupon_id' => 'integer',
        'gift_card_id' => 'integer',
        'reward_points_to_redeem' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
