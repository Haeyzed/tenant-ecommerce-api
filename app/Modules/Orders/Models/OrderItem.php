<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order line with its price, discount and tax snapshots (spec §39.2).
 * A bundle is one line; stock operations expand it.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $product_variant_id
 * @property string $name_snapshot
 * @property string|null $sku_snapshot
 * @property int|null $seller_id
 * @property int|null $warehouse_id
 * @property string $quantity
 * @property string $quantity_shipped
 * @property string $unit_price
 * @property string $price_source
 * @property string|null $unit_cost_snapshot
 * @property string $discount_amount
 * @property string $seller_funded_discount_amount
 * @property string $tax_rate_applied
 * @property string $tax_amount
 * @property array<string, string>|null $tax_breakdown
 * @property string $line_total
 * @property bool $stock_already_deducted
 * @property-read Order $order
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 * @property-read Warehouse|null $warehouse
 */
class OrderItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'order_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'seller_id' => 'integer',
        'warehouse_id' => 'integer',
        'quantity' => 'decimal:3',
        'quantity_shipped' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'unit_cost_snapshot' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'seller_funded_discount_amount' => 'decimal:4',
        'tax_rate_applied' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'tax_breakdown' => 'array',
        'line_total' => 'decimal:4',
        'stock_already_deducted' => 'boolean',
    ];

    /**
     * Holds or consumes stock (simple, variable, bundle).
     */
    public function isPhysical(): bool
    {
        return $this->product !== null && $this->product->isPhysical() && ! $this->stock_already_deducted;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

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
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
