<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A return request and its workflow (spec §41). Named OrderReturn because
 * "return" is reserved.
 *
 * @property int $id
 * @property string|null $return_number
 * @property int $order_id
 * @property int|null $customer_id
 * @property int $return_reason_id
 * @property string $resolution_type refund | exchange
 * @property string $status
 * @property bool|null $requires_physical_return
 * @property string|null $customer_note
 * @property string|null $admin_note
 * @property string|null $rejection_reason
 * @property string|null $refund_amount
 * @property Carbon $requested_at
 * @property Carbon|null $resolved_at
 * @property-read Order $order
 * @property-read ReturnReason $reason
 * @property-read Customer|null $customer
 * @property-read Collection<int, OrderReturnItem> $items
 * @property-read Order|null $replacementOrder
 */
class OrderReturn extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;

    public const string REQUESTED = 'requested';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string AWAITING = 'awaiting_return_shipment';

    public const string RECEIVED = 'received';

    public const string REFUNDED = 'refunded';

    public const string EXCHANGED = 'exchanged';

    public const string CLOSED = 'closed';

    public const array STATUSES = [self::REQUESTED, self::APPROVED, self::REJECTED, self::AWAITING, self::RECEIVED, self::REFUNDED, self::EXCHANGED, self::CLOSED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'order_id' => 'integer',
        'customer_id' => 'integer',
        'return_reason_id' => 'integer',
        'requires_physical_return' => 'boolean',
        'refund_amount' => 'decimal:4',
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->useDisk('local');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ReturnReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReturnReason::class, 'return_reason_id');
    }

    /**
     * @return HasMany<OrderReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class)->orderBy('id');
    }

    /**
     * @return HasOne<Order, $this>
     */
    public function replacementOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'replaces_order_return_id');
    }
}
