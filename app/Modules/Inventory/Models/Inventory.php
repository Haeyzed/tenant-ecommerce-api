<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock of one product (or variant) at one warehouse (spec §32.4): the
 * materialised balance of the movement ledger. Written only by
 * InventoryService; not audited separately (the ledger is its trail).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $variant_key
 * @property int $warehouse_id
 * @property string $quantity
 * @property string $reserved_quantity
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Warehouse $warehouse
 */
class Inventory extends Model
{
    protected $connection = 'tenant';

    protected $table = 'inventory';

    protected $fillable = [];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'variant_key' => 'integer',
        'warehouse_id' => 'integer',
        'quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
    ];

    public function available(): string
    {
        return bcsub((string) $this->quantity, (string) $this->reserved_quantity, 3);
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
