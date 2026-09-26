<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_option_id
 * @property string $value
 * @property int $sort_order
 * @property-read ProductOption $option
 */
class ProductOptionValue extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_option_id', 'value', 'sort_order'];

    protected $casts = ['product_option_id' => 'integer', 'sort_order' => 'integer'];

    /**
     * @return BelongsTo<ProductOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
