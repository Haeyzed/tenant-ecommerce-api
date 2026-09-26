<?php

declare(strict_types=1);

namespace App\Modules\Access\Models;

use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Support\FrontendUrl;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A member of the platform's own team (spec §12.2).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property bool $is_active
 * @property array<string, string|null>|null $preferences
 */
class PlatformUser extends Authenticatable implements CanResetPassword, MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $connection = 'landlord';

    protected string $guard_name = 'platform';

    protected $fillable = ['name', 'email', 'password', 'is_active', 'preferences'];

    protected $hidden = ['password', 'remember_token'];

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

    /**
     * Platform users holding any of the roles, resolved on the landlord
     * connection. spatie's role() scope looks roles up on the default
     * connection, which is the tenant database inside a tenant request.
     *
     * @param  Builder<self>  $query
     * @param  string|list<string>  $roles
     */
    public function scopeWithPlatformRole(Builder $query, string|array $roles): void
    {
        $ids = DB::connection('landlord')->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', (array) $roles)
            ->where('roles.guard_name', 'platform')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->pluck('model_has_roles.model_id');

        $query->whereIn($this->getQualifiedKeyName(), $ids);
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    /**
     * Sent through the platform_user.password_reset template (spec §17.9).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(NotificationDispatchService::class)->dispatch('platform_user.password_reset', $this, [
            'name' => $this->name,
            'reset_url' => FrontendUrl::platformAdmin('/reset-password', ['token' => $token, 'email' => $this->email]),
            'expires_in_minutes' => (int) config('auth.passwords.platform_users.expire', 60),
        ]);
    }

    public function sendEmailVerificationNotification(): void
    {
        app(NotificationDispatchService::class)->dispatch('platform_user.email_verification', $this, [
            'name' => $this->name,
            'verification_url' => FrontendUrl::platformAdmin('/verify-email', EmailVerificationLink::parameters('platform', $this->id, $this->email)),
        ]);
    }
}
