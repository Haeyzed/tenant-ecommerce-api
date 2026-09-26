<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One platform setting row (spec §13.2). Keys are declared in
 * config/platform_settings.php.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class PlatformSetting extends Model implements Auditable
{
    use AuditsToLandlord;

    protected $connection = 'landlord';

    protected $fillable = ['group', 'key', 'value', 'type'];
}
