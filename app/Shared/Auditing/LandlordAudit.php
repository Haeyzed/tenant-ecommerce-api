<?php

declare(strict_types=1);

namespace App\Shared\Auditing;

use OwenIt\Auditing\Models\Audit;

/**
 * An audit row of a landlord model, always written to the landlord database
 * whatever the current context (spec §19.2).
 */
final class LandlordAudit extends Audit
{
    protected $connection = 'landlord';

    protected $table = 'audits';
}
