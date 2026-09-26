<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant attributed to an affiliate at email verification (spec
 * §21A.4). A tenant has at most one referral, ever.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property string $tenant_id
 * @property int|null $tenant_registration_id
 * @property int|null $affiliate_click_id
 * @property int|null $platform_coupon_id
 * @property string $source link | registration_code | coupon
 * @property string $status registered | converted | ineligible | rejected
 * @property string|null $ineligible_reason
 * @property list<array{flag: string, detail: string, detected_at: string}>|null $risk_flags
 * @property bool $requires_review
 * @property Carbon $attributed_at
 * @property Carbon|null $conversion_deadline
 * @property Carbon|null $converted_at
 * @property int|null $reviewed_by
 * @property Carbon $created_at
 * @property-read Affiliate $affiliate
 * @property-read Tenant|null $tenant
 */
class AffiliateReferral extends Model
{
    public const string REGISTERED = 'registered';

    public const string CONVERTED = 'converted';

    public const string INELIGIBLE = 'ineligible';

    public const string REJECTED = 'rejected';

    public const array STATUSES = [self::REGISTERED, self::CONVERTED, self::INELIGIBLE, self::REJECTED];

    protected $connection = 'landlord';

    protected $fillable = [
        'affiliate_id', 'tenant_id', 'tenant_registration_id', 'affiliate_click_id', 'platform_coupon_id', 'source', 'status',
        'ineligible_reason', 'risk_flags', 'requires_review', 'attributed_at', 'conversion_deadline',
    ];

    protected $casts = [
        'risk_flags' => 'array',
        'requires_review' => 'boolean',
        'attributed_at' => 'datetime',
        'conversion_deadline' => 'datetime',
        'converted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Records a review flag once; any flag puts the referral under review.
     */
    public function addFlag(string $flag, string $detail): bool
    {
        $flags = (array) $this->risk_flags;

        foreach ($flags as $existing) {
            if (($existing['flag'] ?? null) === $flag) {
                return false;
            }
        }

        $flags[] = ['flag' => $flag, 'detail' => $detail, 'detected_at' => now()->toIso8601String()];
        $this->risk_flags = $flags;
        $this->requires_review = true;

        return true;
    }
}
