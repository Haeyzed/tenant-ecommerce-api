<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A managed lookup of employee document kinds (spec §58.2).
 *
 * @property int $id
 * @property string $name
 * @property bool $requires_expiry_date
 * @property bool $is_mandatory_at_onboarding
 * @property bool $is_active
 */
class HrDocumentType extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_document_types';

    protected $fillable = [];

    protected $casts = [
        'requires_expiry_date' => 'boolean',
        'is_mandatory_at_onboarding' => 'boolean',
        'is_active' => 'boolean',
    ];
}
