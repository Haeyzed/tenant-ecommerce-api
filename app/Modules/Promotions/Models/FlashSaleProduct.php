<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product's sale price in a flash sale, with an optional quantity cap
 * (spec §37.7). quantity_claimed moves only through claims and releases.
 *
 * @property int $id
 * @property int $flash_sale_id
 * @property int $product_id
 * @property string $sale_price
 * @property string|null $quantity_limit
 * @property string $quantity_claimed
 * @property-read FlashSale $flashSale
 * @property-read Product $product
 */
class FlashSaleProduct extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'sale_price', 'quantity_limit'];

    protected $casts = [
        'flash_sale_id' => 'integer',
        'product_id' => 'integer',
        'sale_price' => 'decimal:4',
        'quantity_limit' => 'decimal:3',
        'quantity_claimed' => 'decimal:3',
    ];

    public function hasRemaining(): bool
    {
        return $this->quantity_limit === null || bccomp((string) $this->quantity_claimed, (string) $this->quantity_limit, 3) < 0;
    }

    /**
     * @return BelongsTo<FlashSale, $this>
     */
    public function flashSale(): BelongsTo
    {
        return $this->belongsTo(FlashSale::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
