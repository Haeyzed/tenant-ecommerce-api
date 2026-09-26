<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $action
 * @property string $quantity
 * @property string|null $unit_cost_snapshot
 * @property string|null $notes
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class StockAdjustmentItem extends Model
{
    public const string ADDITION = 'addition';

    public const string SUBTRACTION = 'subtraction';

    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'product_variant_id', 'action', 'quantity', 'unit_cost_snapshot', 'notes'];

    protected $casts = [
        'stock_adjustment_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity' => 'decimal:3',
        'unit_cost_snapshot' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
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
}
