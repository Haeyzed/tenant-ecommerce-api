<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One movement of money against an order (spec §40.1). status moves only
 * pending → successful or pending → failed, once, under a row lock.
 * Refunds and chargebacks carry a negative amount_paid.
 *
 * @property int $id
 * @property int $order_id
 * @property string $kind payment | refund | chargeback
 * @property string $payment_method
 * @property string|null $provider
 * @property string $mode test | live
 * @property string $status pending | successful | failed
 * @property string $reference
 * @property string|null $idempotency_key
 * @property string|null $provider_reference
 * @property string|null $amount_due
 * @property string|null $amount_received
 * @property string $amount_paid
 * @property string|null $change_given
 * @property string $currency_code
 * @property int|null $refund_of_order_payment_id
 * @property int|null $recorded_by_user_id
 * @property Carbon|null $paid_at
 * @property string|null $notes
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property-read Order $order
 */
class OrderPayment extends Model implements AuditableContract
{
    use Auditable;

    public const string PAYMENT = 'payment';

    public const string REFUND = 'refund';

    public const string CHARGEBACK = 'chargeback';

    public const string PENDING = 'pending';

    public const string SUCCESSFUL = 'successful';

    public const string FAILED = 'failed';

    public const array METHODS = ['gateway', 'cash', 'card_terminal', 'bank_transfer', 'cheque', 'gift_card', 'exchange_credit', 'other'];

    /** Methods staff record by hand (§40.6). */
    public const array MANUAL_METHODS = ['cash', 'bank_transfer', 'cheque', 'other'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'order_id' => 'integer',
        'amount_due' => 'decimal:4',
        'amount_received' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'change_given' => 'decimal:4',
        'exchange_rate_used' => 'decimal:8',
        'refund_of_order_payment_id' => 'integer',
        'recorded_by_user_id' => 'integer',
        'paid_at' => 'datetime',
        'meta' => 'array',
    ];

    public function isManual(): bool
    {
        return $this->kind === self::PAYMENT && in_array($this->payment_method, self::MANUAL_METHODS, true);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return BelongsTo<OrderPayment, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_order_payment_id');
    }

    /**
     * @return HasMany<OrderPayment, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'refund_of_order_payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id')->withTrashed();
    }
}
