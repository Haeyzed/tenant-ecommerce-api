<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Models;

use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a subscription to each order it produced (spec §55.1): the first
 * order, then one per renewal (billing_date = the date it renewed).
 *
 * @property int $id
 * @property int $customer_subscription_id
 * @property int $order_id
 * @property bool $is_renewal
 * @property Carbon|null $billing_date
 * @property Carbon $created_at
 * @property-read CustomerSubscription $subscription
 * @property-read Order $order
 */
class CustomerSubscriptionOrder extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'customer_subscription_id' => 'integer',
        'order_id' => 'integer',
        'is_renewal' => 'boolean',
        'billing_date' => 'date',
    ];

    /**
     * @return BelongsTo<CustomerSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'customer_subscription_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
