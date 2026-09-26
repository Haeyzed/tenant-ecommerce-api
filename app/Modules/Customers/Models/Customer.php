<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\FrontendUrl;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use RuntimeException;

/**
 * A storefront customer (spec §26.1): its own table, guard, token ability
 * and password broker; no roles or permissions. A null password means a
 * record without login (for example a POS walk-in customer).
 *
 * @property int $id
 * @property int|null $customer_group_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password
 * @property Carbon|null $email_verified_at
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $anonymized_at
 * @property Carbon $created_at
 * @property-read CustomerGroup|null $group
 */
class Customer extends Authenticatable implements AuditableContract, CanResetPassword, MustVerifyEmail
{
    use Auditable;
    use HasApiTokens;
    use Notifiable;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = ['customer_group_id', 'name', 'email', 'phone', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['password', 'remember_token', 'last_login_at'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function canLogIn(): bool
    {
        return $this->password !== null && $this->is_active && $this->anonymized_at === null;
    }

    /**
     * Routes mail only to a real address (anonymised customers have none).
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->anonymized_at === null ? $this->email : null;
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(NotificationDispatchService::class)->dispatch('customer.password_reset', $this, [
            'customer_name' => $this->name,
            'store_name' => self::storeName(),
            'reset_url' => FrontendUrl::storefront(self::tenant(), '/reset-password', ['token' => $token, 'email' => (string) $this->email]),
            'expires_in_minutes' => (int) config('auth.passwords.customers.expire', 60),
        ]);
    }

    public function sendEmailVerificationNotification(): void
    {
        app(NotificationDispatchService::class)->dispatch('customer.email_verification', $this, [
            'customer_name' => $this->name,
            'store_name' => self::storeName(),
            'verification_url' => FrontendUrl::storefront(self::tenant(), '/verify-email', EmailVerificationLink::parameters('customer', $this->id, (string) $this->email)),
        ]);
    }

    public static function storeName(): string
    {
        return (string) (app(TenantSettingsService::class)->get('store_name') ?: self::tenant()->name);
    }

    private static function tenant(): Tenant
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Customer notifications run in the tenant context.');
        }

        return $tenant;
    }
}
