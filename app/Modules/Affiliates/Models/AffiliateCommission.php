<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Models;

use App\Modules\Billing\Models\PaymentTransaction;
use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A commission on a referred tenant's first paid charge, or a clawback of
 * a paid one (spec §21A.5). "Eligible" and "payable" are computed views,
 * not states.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property int $affiliate_referral_id
 * @property string $tenant_id
 * @property int $subscription_id
 * @property string $type commission | clawback
 * @property int|null $reverses_commission_id
 * @property int $payment_transaction_id
 * @property string $base_amount
 * @property string $currency_code
 * @property string $commission_rate_applied
 * @property string $amount
 * @property string|null $original_amount
 * @property string $status pending | approved | rejected | reversed | paid
 * @property bool $requires_review
 * @property Carbon $hold_until
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property int|null $affiliate_payout_id
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property-read Affiliate $affiliate
 * @property-read AffiliateReferral $referral
 * @property-read PaymentTransaction $paymentTransaction
 */
class AffiliateCommission extends Model implements Auditable
{
    use AuditsToLandlord;

    public const string COMMISSION = 'commission';

    public const string CLAWBACK = 'clawback';

    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string REVERSED = 'reversed';

    public const string PAID = 'paid';

    public const array STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::REVERSED, self::PAID];

    protected $connection = 'landlord';

    protected $fillable = [
        'affiliate_id', 'affiliate_referral_id', 'tenant_id', 'subscription_id', 'type', 'reverses_commission_id', 'payment_transaction_id',
        'base_amount', 'currency_code', 'commission_rate_applied', 'amount', 'status', 'requires_review', 'hold_until',
    ];

    protected $casts = [
        'base_amount' => 'decimal:4',
        'commission_rate_applied' => 'decimal:4',
        'amount' => 'decimal:4',
        'original_amount' => 'decimal:4',
        'requires_review' => 'boolean',
        'hold_until' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'reversed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return BelongsTo<AffiliateReferral, $this>
     */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(AffiliateReferral::class, 'affiliate_referral_id');
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * Approved rows not in a payout and not under review.
     *
     * @param  Builder<self>  $query
     */
    public function scopePayable(Builder $query): void
    {
        $query->where('status', self::APPROVED)->whereNull('affiliate_payout_id')->where('requires_review', false);
    }

    /**
     * Pending rows past their hold and not under review.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEligible(Builder $query): void
    {
        $query->where('status', self::PENDING)->where('hold_until', '<=', now())->where('requires_review', false);
    }
}
