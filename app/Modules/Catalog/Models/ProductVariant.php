<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A sellable SKU of a variable product: one combination of option values
 * (spec §28.2). A null price or cost falls back to the parent's.
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $price
 * @property string|null $compare_at_price
 * @property string|null $cost_price
 * @property bool $is_active
 * @property-read Product $product
 */
class ProductVariant extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;
    use SoftDeletes;

    public const int MAX_PER_PRODUCT = 100;

    protected $connection = 'tenant';

    protected $fillable = ['sku', 'barcode', 'price', 'compare_at_price', 'cost_price', 'is_active'];

    protected $casts = [
        'product_id' => 'integer',
        'price' => 'decimal:4',
        'compare_at_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile()->useDisk('public');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsToMany<ProductOptionValue, $this>
     */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_option_values');
    }
}
