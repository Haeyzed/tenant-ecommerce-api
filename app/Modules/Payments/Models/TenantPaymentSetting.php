<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A tenant's own credentials for one provider and mode (spec §15.4).
 *
 * @property int $id
 * @property string $provider
 * @property string $mode
 * @property string|null $public_key
 * @property string $secret_key
 * @property string|null $webhook_secret
 * @property bool $is_active
 * @property bool $enabled_for_online
 * @property Carbon|null $credentials_verified_at
 */
class TenantPaymentSetting extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['provider', 'mode', 'public_key', 'secret_key', 'webhook_secret', 'is_active', 'enabled_for_online', 'credentials_verified_at'];

    protected $hidden = ['secret_key', 'webhook_secret'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['secret_key', 'webhook_secret'];

    protected $casts = [
        'secret_key' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'is_active' => 'boolean',
        'enabled_for_online' => 'boolean',
        'credentials_verified_at' => 'datetime',
    ];

    public function maskedPublicKey(): ?string
    {
        return $this->public_key === null ? null : '…'.mb_substr($this->public_key, -4);
    }
}
