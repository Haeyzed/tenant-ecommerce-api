<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $quantity_ordered
 * @property string $quantity_received
 * @property string $unit_cost order currency
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read PurchaseOrder $purchaseOrder
 */
class PurchaseOrderItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'product_variant_id', 'quantity_ordered', 'unit_cost'];

    protected $casts = [
        'purchase_order_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity_ordered' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
