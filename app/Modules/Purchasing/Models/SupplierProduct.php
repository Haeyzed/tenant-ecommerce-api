<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product a supplier sells, with its own reference and cost (spec §49.1).
 *
 * @property int $id
 * @property int $supplier_id
 * @property int $product_id
 * @property string|null $supplier_sku
 * @property string|null $cost_price
 * @property int|null $lead_time_days
 * @property-read Supplier $supplier
 * @property-read Product $product
 */
class SupplierProduct extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['supplier_sku', 'cost_price', 'lead_time_days'];

    protected $casts = ['supplier_id' => 'integer', 'product_id' => 'integer', 'cost_price' => 'decimal:4', 'lead_time_days' => 'integer'];

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
