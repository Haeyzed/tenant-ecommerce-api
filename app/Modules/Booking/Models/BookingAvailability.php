<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One weekly window (spec §66.1): day_of_week 0 = Sunday … 6 = Saturday,
 * wall-clock times in the tenant timezone.
 *
 * @property int $id
 * @property int $booking_staff_id
 * @property int $day_of_week
 * @property string $start_time H:i:s
 * @property string $end_time H:i:s
 */
class BookingAvailability extends Model
{
    protected $table = 'booking_availability';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['booking_staff_id' => 'integer', 'day_of_week' => 'integer'];
}
