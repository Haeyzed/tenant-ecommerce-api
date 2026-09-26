<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A public, pre-purchase question (spec §29.7).
 *
 * @property int $id
 * @property int $product_id
 * @property int $customer_id
 * @property string $question
 * @property bool $is_approved
 * @property Carbon $asked_at
 * @property-read Product $product
 * @property-read Customer $customer
 */
class ProductQuestion extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'customer_id', 'question', 'is_approved', 'asked_at'];

    protected $casts = ['product_id' => 'integer', 'customer_id' => 'integer', 'is_approved' => 'boolean', 'asked_at' => 'datetime'];

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

    /**
     * @return HasMany<ProductAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ProductAnswer::class)->orderBy('id');
    }
}
