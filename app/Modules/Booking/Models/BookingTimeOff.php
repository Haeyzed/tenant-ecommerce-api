<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-off absence (spec §66.1).
 *
 * @property int $id
 * @property int $booking_staff_id
 * @property Carbon $start_datetime
 * @property Carbon $end_datetime
 * @property string|null $reason
 */
class BookingTimeOff extends Model
{
    protected $table = 'booking_time_off';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['booking_staff_id' => 'integer', 'start_datetime' => 'datetime', 'end_datetime' => 'datetime'];
}
