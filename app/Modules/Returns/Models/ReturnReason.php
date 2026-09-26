<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Why a customer returns items (spec §41.1).
 *
 * @property int $id
 * @property string $label
 * @property bool $requires_photo
 * @property bool $is_active
 */
class ReturnReason extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['label', 'requires_photo', 'is_active'];

    protected $casts = ['requires_photo' => 'boolean', 'is_active' => 'boolean'];
}
