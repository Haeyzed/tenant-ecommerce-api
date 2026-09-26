<?php

declare(strict_types=1);

namespace App\Shared\Auditing;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Auditable;

/**
 * Field-level auditing for landlord models (spec §19.2). Audits go to the
 * landlord audits table even when the change is made from a tenant context.
 */
trait AuditsToLandlord
{
    use Auditable;

    public function audits(): MorphMany
    {
        return $this->morphMany(LandlordAudit::class, 'auditable');
    }
}
