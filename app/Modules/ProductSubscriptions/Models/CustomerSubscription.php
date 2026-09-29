<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shipping\Models\ShippingMethod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A customer's recurring order of one product (spec §55.1). The stored
 * payment authorization is encrypted, hidden from every payload and never
 * audited.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $product_subscription_plan_id
 * @property string $quantity
 * @property int|null $address_id
 * @property int|null $shipping_method_id
 * @property string $currency_code
 * @property string $status
 * @property string|null $payment_provider
 * @property string|null $payment_method_token encrypted
 * @property Carbon|null $next_billing_date
 * @property int $failed_renewal_count
 * @property string|null $last_renewal_error
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property-read Customer $customer
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read ProductSubscriptionPlan $plan
 * @property-read Address|null $address
 * @property-read ShippingMethod|null $shippingMethod
 * @property-read Collection<int, CustomerSubscriptionOrder> $subscriptionOrders
 */
class CustomerSubscription extends Model implements AuditableContract
{
    use Auditable;

    public const string PENDING_PAYMENT = 'pending_payment';

    public const string ACTIVE = 'active';

    public const string PAUSED = 'paused';

    public const string CANCELLED = 'cancelled';

    public const string PAYMENT_FAILED = 'payment_failed';

    public const array STATUSES = [self::PENDING_PAYMENT, self::ACTIVE, self::PAUSED, self::CANCELLED, self::PAYMENT_FAILED];

    /** Statuses that still commit the store to the customer (§11.5). */
    public const array LIVE = [self::ACTIVE, self::PAUSED, self::PAYMENT_FAILED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $hidden = ['payment_method_token'];

    /** @var list<string> */
    protected array $auditExclude = ['payment_method_token'];

    protected $casts = [
        'customer_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'product_subscription_plan_id' => 'integer',
        'quantity' => 'decimal:3',
        'address_id' => 'integer',
        'shipping_method_id' => 'integer',
        'payment_method_token' => 'encrypted',
        'next_billing_date' => 'date',
        'failed_renewal_count' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

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
     * @return BelongsTo<ProductSubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductSubscriptionPlan::class, 'product_subscription_plan_id');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * @return HasMany<CustomerSubscriptionOrder, $this>
     */
    public function subscriptionOrders(): HasMany
    {
        return $this->hasMany(CustomerSubscriptionOrder::class)->orderBy('id');
    }
}
