<?php

declare(strict_types=1);

namespace App\Shared\Activity;

use Spatie\Activitylog\Models\Activity;

/**
 * An activity-log row of the landlord database, written whatever the
 * current context (spec §19.1).
 */
final class LandlordActivity extends Activity
{
    protected $connection = 'landlord';

    protected $table = 'activity_log';
}
