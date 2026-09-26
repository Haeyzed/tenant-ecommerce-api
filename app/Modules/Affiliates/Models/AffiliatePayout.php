<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One payout of an affiliate's payable balance in one currency for one
 * period (spec §21A.6). Money is sent outside the platform.
 *
 * @property int $id
 * @property string $reference
 * @property int $affiliate_id
 * @property string $currency_code
 * @property string $amount
 * @property int $commission_count
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status pending | paid | failed | cancelled
 * @property string $payout_method
 * @property array<string, string> $payout_details_snapshot
 * @property string|null $external_reference
 * @property Carbon|null $paid_at
 * @property int|null $paid_by
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Affiliate $affiliate
 */
class AffiliatePayout extends Model implements Auditable
{
    use AuditsToLandlord;

    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::PENDING, self::PAID, self::FAILED, self::CANCELLED];

    protected $connection = 'landlord';

    protected $fillable = [
        'reference', 'affiliate_id', 'currency_code', 'amount', 'commission_count', 'period_start', 'period_end', 'status',
        'payout_method', 'payout_details_snapshot', 'created_by',
    ];

    protected $hidden = ['payout_details_snapshot'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['payout_details_snapshot'];

    protected $casts = [
        'amount' => 'decimal:4',
        'commission_count' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'payout_details_snapshot' => 'encrypted:array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return HasMany<AffiliateCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }
}
