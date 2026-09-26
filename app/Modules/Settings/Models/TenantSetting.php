<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One tenant business setting (spec §13.4). Never exposed to the public.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $type
 */
class TenantSetting extends Model
{
    protected $fillable = ['key', 'value', 'type'];
}
