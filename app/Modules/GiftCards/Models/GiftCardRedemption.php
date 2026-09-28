<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Models;

use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement on a gift card (spec §46.1): positive redeemed, negative
 * reversed back onto the card.
 *
 * @property int $id
 * @property int $gift_card_id
 * @property int $order_id
 * @property int $order_payment_id
 * @property string $amount
 * @property Carbon|null $created_at
 * @property-read Order $order
 */
class GiftCardRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['gift_card_id' => 'integer', 'order_id' => 'integer', 'order_payment_id' => 'integer', 'amount' => 'decimal:4'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
