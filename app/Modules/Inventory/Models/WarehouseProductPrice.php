<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A product's (or variant's) price at one warehouse (spec §34). Read only
 * when the product has has_warehouse_pricing.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $warehouse_id
 * @property string $price
 * @property string|null $compare_at_price
 * @property-read Warehouse $warehouse
 * @property-read ProductVariant|null $variant
 */
class WarehouseProductPrice extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['price', 'compare_at_price'];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'price' => 'decimal:4',
        'compare_at_price' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
