<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $quantity_sent
 * @property string $quantity_received
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class StockTransferItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'product_variant_id', 'quantity_sent'];

    protected $casts = [
        'stock_transfer_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity_sent' => 'decimal:3',
        'quantity_received' => 'decimal:3',
    ];

    public function remaining(): string
    {
        return bcsub((string) $this->quantity_sent, (string) $this->quantity_received, 3);
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
