<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A referral or commission rep credited for sales the store fulfils (spec
 * §52.1). An external agent has no login (user_id null).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string $agent_code
 * @property string|null $commission_rate percent; null = the store default
 * @property string $status
 * @property-read User|null $user
 */
class SalesAgent extends Model implements AuditableContract
{
    use Auditable;
    use Notifiable;

    public const string ACTIVE = 'active';

    public const string INACTIVE = 'inactive';

    protected $connection = 'tenant';

    protected $fillable = ['user_id', 'name', 'phone', 'email', 'agent_code', 'commission_rate'];

    protected $casts = [
        'user_id' => 'integer',
        'commission_rate' => 'decimal:4',
    ];

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
