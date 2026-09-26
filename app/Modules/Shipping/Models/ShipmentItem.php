<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shipment_id
 * @property int $order_item_id
 * @property string $quantity
 * @property-read OrderItem $orderItem
 */
class ShipmentItem extends Model
{
    public $timestamps = false;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['shipment_id' => 'integer', 'order_item_id' => 'integer', 'quantity' => 'decimal:3'];

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
