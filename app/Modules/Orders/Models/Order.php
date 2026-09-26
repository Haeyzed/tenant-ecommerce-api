<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Modules\Shipping\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The single record of a sale, whatever its origin (spec §39.1). Created
 * only through OrderService::createOrder(). Money columns are in
 * currency_code.
 *
 * @property int $id
 * @property string $order_number
 * @property string|null $invoice_number
 * @property int|null $customer_id
 * @property string|null $guest_token
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string $status
 * @property string $payment_status
 * @property string $order_type
 * @property string $order_source
 * @property bool $is_test
 * @property string $currency_code
 * @property bool $prices_include_tax
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $shipping_amount
 * @property string $shipping_discount_amount
 * @property string $shipping_tax_amount
 * @property string $tax_amount
 * @property int $reward_points_redeemed
 * @property string $reward_points_discount_amount
 * @property string $gift_card_amount_applied
 * @property string $total
 * @property int|null $shipping_method_id
 * @property array<string, mixed>|null $shipping_address
 * @property array<string, mixed>|null $billing_address
 * @property string|null $payment_gateway
 * @property int|null $created_by_user_id
 * @property string|null $idempotency_key
 * @property string|null $customer_note
 * @property Carbon $placed_at
 * @property Carbon|null $payment_expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property-read Customer|null $customer
 * @property-read Collection<int, OrderItem> $items
 */
class Order extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    public const string PENDING = 'pending';

    public const string PROCESSING = 'processing';

    public const string PARTIALLY_SHIPPED = 'partially_shipped';

    public const string SHIPPED = 'shipped';

    public const string DELIVERED = 'delivered';

    public const string COMPLETED = 'completed';

    public const string CANCELLED = 'cancelled';

    public const string REFUNDED = 'refunded';

    public const array STATUSES = [self::PENDING, self::PROCESSING, self::PARTIALLY_SHIPPED, self::SHIPPED, self::DELIVERED, self::COMPLETED, self::CANCELLED, self::REFUNDED];

    public const array PAYMENT_STATUSES = ['unpaid', 'partially_paid', 'paid', 'failed', 'partially_refunded', 'refunded'];

    public const array SOURCES = ['online', 'pos', 'woocommerce', 'social', 'admin'];

    public const array TYPES = ['standard', 'gift_card_purchase', 'exchange_replacement'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'customer_id' => 'integer',
        'is_test' => 'boolean',
        'prices_include_tax' => 'boolean',
        'subtotal' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'shipping_amount' => 'decimal:4',
        'shipping_discount_amount' => 'decimal:4',
        'shipping_tax_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'reward_points_redeemed' => 'integer',
        'reward_points_discount_amount' => 'decimal:4',
        'gift_card_amount_applied' => 'decimal:4',
        'total' => 'decimal:4',
        'base_currency_amount' => 'decimal:4',
        'exchange_rate_used' => 'decimal:8',
        'shipping_method_id' => 'integer',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'created_by_user_id' => 'integer',
        'placed_at' => 'datetime',
        'payment_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class)->withTrashed();
    }
}
