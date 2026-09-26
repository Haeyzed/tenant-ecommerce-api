<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_return_id
 * @property int $order_item_id
 * @property string $quantity
 * @property string|null $condition resellable | damaged | missing_parts
 * @property bool $restocked
 * @property int|null $exchange_for_product_id
 * @property int|null $exchange_for_product_variant_id
 * @property-read OrderItem $orderItem
 * @property-read Product|null $exchangeProduct
 * @property-read ProductVariant|null $exchangeVariant
 */
class OrderReturnItem extends Model
{
    public const array CONDITIONS = ['resellable', 'damaged', 'missing_parts'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'order_return_id' => 'integer',
        'order_item_id' => 'integer',
        'quantity' => 'decimal:3',
        'restocked' => 'boolean',
        'exchange_for_product_id' => 'integer',
        'exchange_for_product_variant_id' => 'integer',
    ];

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function exchangeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'exchange_for_product_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function exchangeVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'exchange_for_product_variant_id')->withTrashed();
    }
}
