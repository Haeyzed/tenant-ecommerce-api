<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One storefront presentation setting (spec §13.5). Every key is public.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class StorefrontSetting extends Model implements AuditableContract
{
    use Auditable;

    protected $fillable = ['key', 'value', 'type'];
}
