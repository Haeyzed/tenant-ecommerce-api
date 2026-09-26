<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's review of a product (spec §42.1). One per customer and
 * product; a new submission replaces it.
 *
 * @property int $id
 * @property int $product_id
 * @property int $customer_id
 * @property int|null $order_id
 * @property int $rating 1–5
 * @property string|null $title
 * @property string $body
 * @property string $status pending | approved | rejected
 * @property string|null $rejection_reason
 * @property bool $is_verified_purchase
 * @property Carbon $created_at
 * @property-read Product $product
 * @property-read Customer $customer
 */
class ProductReview extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'product_id' => 'integer',
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'rating' => 'integer',
        'is_verified_purchase' => 'boolean',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
