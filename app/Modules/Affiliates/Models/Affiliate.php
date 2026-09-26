<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Models;

use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Auditing\AuditsToLandlord;
use App\Shared\Support\FrontendUrl;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A person or company referring new businesses to the platform (spec
 * §21A.2). Totals are always computed from referrals and commissions.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property string $password
 * @property string|null $company_name
 * @property string|null $website_url
 * @property int|null $country_id
 * @property string|null $promotion_methods
 * @property string|null $referral_code
 * @property string $status
 * @property string|null $commission_rate
 * @property string|null $rejection_reason
 * @property string|null $status_reason
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $payout_method
 * @property array<string, string>|null $payout_details
 * @property Carbon|null $payout_details_updated_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip_hash
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Affiliate extends Authenticatable implements Auditable, CanResetPassword, MustVerifyEmail
{
    use AuditsToLandlord;
    use HasApiTokens;
    use Notifiable;

    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string SUSPENDED = 'suspended';

    public const string CLOSED = 'closed';

    public const array STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::SUSPENDED, self::CLOSED];

    public const array PAYOUT_METHODS = ['bank_transfer', 'paypal', 'other'];

    protected $connection = 'landlord';

    protected $fillable = ['name', 'email', 'phone', 'password', 'company_name', 'website_url', 'country_id', 'promotion_methods'];

    protected $hidden = ['password', 'payout_details', 'last_login_ip_hash'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['password', 'payout_details', 'last_login_ip_hash', 'last_login_at'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'commission_rate' => 'decimal:4',
            'approved_at' => 'datetime',
            'payout_details' => 'encrypted:array',
            'payout_details_updated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    /**
     * @return HasMany<AffiliateReferral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    /**
     * @return HasMany<AffiliateCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /**
     * The public referral link; only approved affiliates have one.
     */
    public function referralLink(): ?string
    {
        return $this->isApproved() && $this->referral_code !== null ? FrontendUrl::website('/', ['ref' => $this->referral_code]) : null;
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(NotificationDispatchService::class)->dispatch('affiliate.password_reset', $this, [
            'name' => $this->name,
            'reset_url' => FrontendUrl::affiliatePortal('/reset-password', ['token' => $token, 'email' => $this->email]),
            'expires_in_minutes' => (int) config('auth.passwords.affiliates.expire', 60),
        ]);
    }

    public function sendEmailVerificationNotification(): void
    {
        app(NotificationDispatchService::class)->dispatch('affiliate.email_verification', $this, [
            'name' => $this->name,
            'verification_url' => FrontendUrl::affiliatePortal('/verify-email', EmailVerificationLink::parameters('affiliate', $this->id, $this->email)),
        ]);
    }
}
