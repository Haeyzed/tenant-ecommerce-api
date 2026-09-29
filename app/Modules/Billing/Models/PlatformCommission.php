<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The platform's commission on one live tenant payment, or its reversal on
 * a refund (spec §15.8, D-138). pending → billed (on a renewal charge) →
 * collected; a failed charge returns its rows to pending. A platform admin
 * can waive a pending row.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $source_reference
 * @property string $kind payment | refund
 * @property string|null $order_number
 * @property string $base_amount
 * @property string $rate
 * @property string $amount negative for a refund
 * @property string $currency_code
 * @property string $status pending | billed | collected | waived
 * @property int|null $payment_transaction_id
 * @property int|null $waived_by
 * @property string|null $waived_reason
 * @property Carbon|null $collected_at
 * @property Carbon|null $created_at
 */
class PlatformCommission extends Model implements Auditable
{
    use AuditsToLandlord;

    public const string PAYMENT = 'payment';

    public const string REFUND = 'refund';

    public const string PENDING = 'pending';

    public const string BILLED = 'billed';

    public const string COLLECTED = 'collected';

    public const string WAIVED = 'waived';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'source_reference', 'kind', 'order_number', 'base_amount', 'rate', 'amount', 'currency_code',
        'status', 'payment_transaction_id', 'waived_by', 'waived_reason', 'collected_at',
    ];

    protected $casts = [
        'base_amount' => 'decimal:4',
        'rate' => 'decimal:4',
        'amount' => 'decimal:4',
        'collected_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
