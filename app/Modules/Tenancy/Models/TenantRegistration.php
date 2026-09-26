<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Plans\Models\PlanPrice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sign-up awaiting email verification (spec §9.3). Costs one landlord row;
 * no tenant or database exists before verification.
 *
 * @property int $id
 * @property string $public_id
 * @property string $business_name
 * @property string $slug
 * @property string $owner_name
 * @property string $email
 * @property string|null $password_hash bcrypt hash, encrypted at rest; cleared after provisioning
 * @property int $country_id
 * @property string $default_currency
 * @property int $plan_price_id
 * @property int|null $platform_coupon_id
 * @property int|null $affiliate_click_id
 * @property string|null $affiliate_source link | registration_code | coupon (§21A.4)
 * @property string $verification_token_hash
 * @property Carbon $verification_expires_at
 * @property int $verification_attempts
 * @property string $status pending_verification | converted | expired
 * @property Carbon|null $verified_at
 * @property string|null $tenant_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property-read PlanPrice $planPrice
 * @property-read Tenant|null $tenant
 */
class TenantRegistration extends Model
{
    public const string PENDING = 'pending_verification';

    public const string CONVERTED = 'converted';

    public const string EXPIRED = 'expired';

    public const int MAX_ATTEMPTS = 5;

    protected $connection = 'landlord';

    protected $fillable = [
        'public_id', 'business_name', 'slug', 'owner_name', 'email', 'password_hash', 'country_id', 'default_currency',
        'plan_price_id', 'platform_coupon_id', 'affiliate_click_id', 'affiliate_source', 'verification_token_hash', 'verification_expires_at',
        'verification_attempts', 'status', 'verified_at', 'tenant_id', 'ip_address', 'user_agent',
    ];

    protected $hidden = ['password_hash', 'verification_token_hash'];

    protected $casts = [
        'password_hash' => 'encrypted',
        'country_id' => 'integer',
        'verification_expires_at' => 'datetime',
        'verification_attempts' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<PlanPrice, $this>
     */
    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    /**
     * @return BelongsTo<PlatformCoupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PlatformCoupon::class, 'platform_coupon_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
