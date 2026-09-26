<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The flash-sale quantity one order claimed (spec §37.7), so release
 * returns exactly that quantity to the sale row it came from.
 *
 * @property int $id
 * @property int $flash_sale_product_id
 * @property int $order_id
 * @property string $quantity
 * @property string $status claimed | released
 */
class FlashSaleClaim extends Model
{
    public const string CLAIMED = 'claimed';

    public const string RELEASED = 'released';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['flash_sale_product_id' => 'integer', 'order_id' => 'integer', 'quantity' => 'decimal:3'];

    /**
     * @return BelongsTo<FlashSaleProduct, $this>
     */
    public function flashSaleProduct(): BelongsTo
    {
        return $this->belongsTo(FlashSaleProduct::class);
    }
}
