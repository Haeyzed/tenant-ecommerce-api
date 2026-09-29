<?php

declare(strict_types=1);

namespace App\Modules\BackInStock\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "notify me when available" request (spec §56). Guests subscribe by
 * email alone. notified_at is stamped when the alert fires, so nobody is
 * notified twice for the same restock.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $customer_id
 * @property string $email
 * @property Carbon|null $notified_at
 * @property Carbon $created_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Customer|null $customer
 */
class BackInStockSubscription extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $hidden = ['pending_key'];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'customer_id' => 'integer',
        'notified_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
