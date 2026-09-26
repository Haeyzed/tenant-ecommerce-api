<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The tenant's own WhatsApp Business number (spec §16.4). Singleton.
 *
 * @property int $id
 * @property string|null $phone_number_id
 * @property string|null $business_account_id
 * @property string|null $access_token
 * @property bool $is_active
 * @property Carbon|null $verified_at
 */
class WhatsAppSetting extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $table = 'whatsapp_settings';

    protected $fillable = ['phone_number_id', 'business_account_id', 'access_token', 'is_active', 'verified_at'];

    protected $hidden = ['access_token'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function hasCredentials(): bool
    {
        return filled($this->phone_number_id) && filled($this->access_token);
    }
}
