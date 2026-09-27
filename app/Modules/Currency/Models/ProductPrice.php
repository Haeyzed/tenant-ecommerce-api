<?php

declare(strict_types=1);

namespace App\Modules\Currency\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The store's deliberate price for one market (spec §48.1): authoritative
 * for its currency, never repriced by exchange rates. A variant row wins
 * over the product row.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $currency_code
 * @property string $price
 * @property string|null $compare_at_price
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class ProductPrice extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['currency_code', 'price', 'compare_at_price'];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'price' => 'decimal:4',
        'compare_at_price' => 'decimal:4',
    ];

    /** @var list<string> */
    protected array $auditExclude = ['variant_key'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
