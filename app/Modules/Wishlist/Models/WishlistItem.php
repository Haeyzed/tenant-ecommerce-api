<?php

declare(strict_types=1);

namespace App\Modules\Wishlist\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product a customer saved for later (spec §42.2).
 *
 * @property int $id
 * @property int $customer_id
 * @property int $product_id
 * @property Carbon $created_at
 * @property-read Product $product
 */
class WishlistItem extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['customer_id' => 'integer', 'product_id' => 'integer', 'created_at' => 'datetime'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
