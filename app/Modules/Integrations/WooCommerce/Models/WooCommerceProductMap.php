<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product (or variant) and its WooCommerce product (or variation). A
 * variable product has a parent row (no variant, no variation) and one
 * row per variation.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $woocommerce_product_id
 * @property int|null $woocommerce_variation_id
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $stock_dirty_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class WooCommerceProductMap extends Model
{
    protected $table = 'woocommerce_product_map';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'woocommerce_product_id' => 'integer',
        'woocommerce_variation_id' => 'integer',
        'last_synced_at' => 'datetime',
        'stock_dirty_at' => 'datetime',
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
}
