<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a recipe, per yield_quantity batch.
 *
 * @property int $id
 * @property int $bill_of_material_id
 * @property int $component_product_id
 * @property int|null $component_product_variant_id
 * @property string $quantity_required
 * @property string|null $unit_cost_snapshot
 * @property-read Product $component
 * @property-read ProductVariant|null $componentVariant
 */
class BillOfMaterialItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'bill_of_material_id' => 'integer',
        'component_product_id' => 'integer',
        'component_product_variant_id' => 'integer',
        'quantity_required' => 'decimal:3',
        'unit_cost_snapshot' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_product_variant_id')->withTrashed();
    }
}
