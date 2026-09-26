<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\FrontendUrl;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use RuntimeException;
use Spatie\Permission\Traits\HasRoles;

/**
 * A tenant staff user (spec §25.1). The only tenant actor with roles and
 * permissions (guard "staff").
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $phone
 * @property bool $is_active
 * @property array<string, string|null>|null $preferences
 */
class User extends Authenticatable implements AuditableContract, CanResetPassword
{
    use Auditable;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected string $guard_name = 'staff';

    protected $fillable = ['name', 'email', 'password', 'phone', 'is_active', 'preferences'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'preferences' => 'array',
            'password' => 'hashed',
        ];
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    /**
     * Sent through the staff.password_reset template (spec §17.9); the link
     * points at the tenant's admin frontend.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('Staff password resets run in the tenant context.');
        }

        app(NotificationDispatchService::class)->dispatch('staff.password_reset', $this, [
            'name' => $this->name,
            'reset_url' => FrontendUrl::tenantAdmin($tenant, '/reset-password', ['token' => $token, 'email' => $this->email]),
            'expires_in_minutes' => (int) config('auth.passwords.users.expire', 60),
        ]);
    }
}
