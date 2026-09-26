<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The tenant's activation choice for one module (spec §11.7). Never deleted
 * while the tenant exists: its existence distinguishes "locked" from
 * "unavailable".
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $module_key
 * @property string $status enabled | disabled
 * @property Carbon|null $enabled_at
 * @property Carbon|null $disabled_at
 * @property int|null $changed_by_user_id
 * @property string|null $changed_by_email
 */
class TenantModule extends Model implements Auditable
{
    use AuditsToLandlord;

    public const string ENABLED = 'enabled';

    public const string DISABLED = 'disabled';

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'module_key', 'status', 'enabled_at', 'disabled_at', 'changed_by_user_id', 'changed_by_email'];

    protected $casts = [
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];
}
