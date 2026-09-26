<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One parcel or delivery run of an order from one warehouse (spec §36.2).
 *
 * @property int $id
 * @property int $order_id
 * @property int $warehouse_id
 * @property int|null $shipping_method_id
 * @property string $fulfillment_type courier | in_house
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property string $status pending | dispatched | in_transit | delivered | failed | cancelled
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $delivered_at
 * @property int|null $created_by_user_id
 * @property-read Order $order
 * @property-read Warehouse $warehouse
 * @property-read Collection<int, ShipmentItem> $items
 * @property-read DeliveryAssignment|null $assignment
 */
class Shipment extends Model implements AuditableContract
{
    use Auditable;

    public const string PENDING = 'pending';

    public const string DISPATCHED = 'dispatched';

    public const string IN_TRANSIT = 'in_transit';

    public const string DELIVERED = 'delivered';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::PENDING, self::DISPATCHED, self::IN_TRANSIT, self::DELIVERED, self::FAILED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'order_id' => 'integer',
        'warehouse_id' => 'integer',
        'shipping_method_id' => 'integer',
        'created_by_user_id' => 'integer',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Counts its quantity as shipped (dispatched and not cancelled).
     */
    public function isOnTheWay(): bool
    {
        return in_array($this->status, [self::DISPATCHED, self::IN_TRANSIT, self::DELIVERED], true);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class)->withTrashed();
    }

    /**
     * @return HasMany<ShipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class)->orderBy('id');
    }

    /**
     * @return HasOne<DeliveryAssignment, $this>
     */
    public function assignment(): HasOne
    {
        return $this->hasOne(DeliveryAssignment::class);
    }
}
