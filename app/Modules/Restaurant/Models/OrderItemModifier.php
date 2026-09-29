<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A modifier chosen on an order line, snapshotted (spec §65.2, §5.8).
 *
 * @property int $id
 * @property int $order_item_id
 * @property int|null $modifier_option_id
 * @property string $name_snapshot
 * @property string $price_adjustment_snapshot in the order's currency
 */
class OrderItemModifier extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['order_item_id' => 'integer', 'modifier_option_id' => 'integer', 'price_adjustment_snapshot' => 'decimal:4'];
}
