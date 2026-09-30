<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\FrontendUrl;
use Illuminate\Contracts\Auth\CanResetPassword;
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
 * A third-party vendor listing products under the tenant's storefront
 * (spec §50.1). Its own table, guard (seller), token ability and password
 * broker; not staff and not a customer. Only approved sellers log in.
 *
 * @property int $id
 * @property int|null $seller_group_id
 * @property string $business_name
 * @property string|null $contact_name
 * @property string $email
 * @property string|null $phone
 * @property string $password
 * @property Carbon|null $email_verified_at
 * @property string $status
 * @property string|null $rejection_reason
 * @property string|null $commission_rate percent; null = the group's, else the store default
 * @property Carbon|null $approved_at
 * @property Carbon|null $last_login_at
 * @property-read SellerGroup|null $group
 */
class Seller extends Authenticatable implements AuditableContract, CanResetPassword
{
    use Auditable;
    use HasApiTokens;
    use Notifiable;
    use SoftDeletes;

    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string SUSPENDED = 'suspended';

    public const array STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::SUSPENDED];

    protected $connection = 'tenant';

    protected $fillable = ['business_name', 'contact_name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['password', 'remember_token', 'last_login_at'];

    protected function casts(): array
    {
        return [
            'seller_group_id' => 'integer',
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            'commission_rate' => 'decimal:4',
            'password' => 'hashed',
        ];
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    /**
     * @return BelongsTo<SellerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SellerGroup::class, 'seller_group_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    /**
     * The seller.* templates' {{seller_name}}.
     */
    public function displayName(): string
    {
        return $this->business_name;
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Seller notifications run in the tenant context.');
        }

        app(NotificationDispatchService::class)->dispatch('seller.password_reset', $this, [
            'seller_name' => $this->business_name,
            'store_name' => Customer::storeName(),
            'reset_url' => FrontendUrl::sellerPortal($tenant, '/reset-password', ['token' => $token, 'email' => $this->email]),
            'expires_in_minutes' => (int) config('auth.passwords.sellers.expire', 60),
        ]);
    }
}
