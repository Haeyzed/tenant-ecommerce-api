<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recipe for a finished product or variant (spec §64.1). A product may
 * have several; at most one is its default.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $name
 * @property bool $is_default
 * @property string $yield_quantity
 * @property string|null $notes
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Collection<int, BillOfMaterialItem> $items
 */
class BillOfMaterial extends Model
{
    protected $connection = 'tenant';

    protected $table = 'bill_of_materials';

    protected $fillable = [];

    protected $hidden = ['default_key'];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'is_default' => 'boolean',
        'yield_quantity' => 'decimal:3',
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

    /**
     * @return HasMany<BillOfMaterialItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BillOfMaterialItem::class)->orderBy('id');
    }
}
