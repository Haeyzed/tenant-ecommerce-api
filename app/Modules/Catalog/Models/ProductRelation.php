<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $related_product_id
 * @property string $relation_type related | upsell | cross_sell
 * @property int $sort_order
 * @property-read Product $related
 */
class ProductRelation extends Model
{
    public const array TYPES = ['related', 'upsell', 'cross_sell'];

    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'related_product_id', 'relation_type', 'sort_order'];

    protected $casts = ['product_id' => 'integer', 'related_product_id' => 'integer', 'sort_order' => 'integer'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function related(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
