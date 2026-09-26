<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One child line of a bundle (spec §28.3).
 *
 * @property int $id
 * @property int $bundle_product_id
 * @property int $child_product_id
 * @property int|null $child_product_variant_id
 * @property string $quantity
 * @property-read Product $child
 * @property-read ProductVariant|null $childVariant
 */
class ProductBundleItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['bundle_product_id', 'child_product_id', 'child_product_variant_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3', 'child_product_id' => 'integer', 'child_product_variant_id' => 'integer'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'child_product_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function childVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'child_product_variant_id');
    }
}
