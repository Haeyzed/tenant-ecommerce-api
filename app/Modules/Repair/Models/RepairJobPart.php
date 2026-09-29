<?php

declare(strict_types=1);

namespace App\Modules\Repair\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part fitted to a repair (spec §67.3): it left stock when added.
 *
 * @property int $id
 * @property int $repair_job_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $quantity
 * @property string|null $unit_cost_snapshot
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class RepairJobPart extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'repair_job_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity' => 'decimal:3',
        'unit_cost_snapshot' => 'decimal:4',
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
