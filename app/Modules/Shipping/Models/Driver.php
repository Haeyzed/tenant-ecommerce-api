<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An in-house delivery driver (spec §36.2): its own guard ("driver"),
 * signing in with phone and PIN (§10.3). No roles or permissions; driver
 * routes see only the driver's own deliveries.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $pin_hash
 * @property string|null $vehicle_type
 * @property string $status active | inactive
 * @property bool $is_available
 * @property Carbon|null $last_login_at
 * @property-read User|null $user
 */
class Driver extends Authenticatable implements AuditableContract
{
    use Auditable;
    use HasApiTokens;
    use Notifiable;

    public const string ACTIVE = 'active';

    public const string INACTIVE = 'inactive';

    protected $connection = 'tenant';

    protected $fillable = ['user_id', 'name', 'phone', 'vehicle_type', 'is_available'];

    protected $hidden = ['pin_hash'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['pin_hash', 'last_login_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_available' => 'boolean',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->pin_hash;
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
