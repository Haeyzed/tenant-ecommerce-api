<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_return_id
 * @property int $purchase_order_item_id
 * @property string $quantity
 * @property string $unit_cost copied from the purchase-order line
 * @property-read PurchaseOrderItem $orderItem
 */
class PurchaseReturnItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['purchase_order_item_id', 'quantity', 'unit_cost'];

    protected $casts = ['purchase_return_id' => 'integer', 'purchase_order_item_id' => 'integer', 'quantity' => 'decimal:3', 'unit_cost' => 'decimal:4'];

    /**
     * @return BelongsTo<PurchaseOrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
