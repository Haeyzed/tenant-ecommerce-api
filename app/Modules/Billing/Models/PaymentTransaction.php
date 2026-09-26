<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One movement of platform billing money (spec §14.1). Refunds and
 * chargebacks are their own negative rows; a charge is never re-labelled.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $subscription_id
 * @property string $type charge | authorization | refund | chargeback
 * @property string $mode test | live
 * @property string $provider
 * @property string $reference
 * @property string|null $provider_reference
 * @property string $amount
 * @property string $currency_code
 * @property string $status pending | successful | failed
 * @property int|null $refund_of_payment_transaction_id
 * @property bool $is_first_paid_charge
 * @property array<int, array<string, mixed>>|null $line_items
 * @property string|null $fee
 * @property string|null $failure_reason
 * @property string|null $reason
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $paid_at
 * @property-read Subscription $subscription
 */
class PaymentTransaction extends Model implements Auditable
{
    use AuditsToLandlord;

    public const string CHARGE = 'charge';

    public const string AUTHORIZATION = 'authorization';

    public const string REFUND = 'refund';

    public const string CHARGEBACK = 'chargeback';

    public const string PENDING = 'pending';

    public const string SUCCESSFUL = 'successful';

    public const string FAILED = 'failed';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'type', 'mode', 'provider', 'reference', 'provider_reference', 'amount',
        'currency_code', 'status', 'refund_of_payment_transaction_id', 'is_first_paid_charge', 'line_items', 'fee',
        'failure_reason', 'reason', 'refunded_by', 'meta', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'fee' => 'decimal:4',
        'is_first_paid_charge' => 'boolean',
        'line_items' => 'array',
        'meta' => 'array',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<self, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'refund_of_payment_transaction_id');
    }
}
