<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A per-tenant setting the landlord reads or enforces (spec §13.3).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class TenantPlatformSetting extends Model implements Auditable
{
    use AuditsToLandlord;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'key', 'value', 'type'];
}
