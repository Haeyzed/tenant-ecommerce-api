<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quotation_request_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $quantity_requested
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class QuotationRequestItem extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'product_variant_id', 'quantity_requested'];

    protected $casts = ['quotation_request_id' => 'integer', 'product_id' => 'integer', 'product_variant_id' => 'integer', 'quantity_requested' => 'decimal:3'];

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
