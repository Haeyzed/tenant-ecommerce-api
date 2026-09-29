<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A shift template (spec §58.3a): working hours, an unpaid break and
 * optional grace minutes. An end time earlier than the start time ends the
 * next day (a night shift).
 *
 * @property int $id
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property int|null $late_grace_minutes
 * @property int|null $early_leave_grace_minutes
 * @property string|null $color
 * @property bool $is_active
 */
class HrShift extends Model
{
    protected $connection = 'tenant';

    protected $table = 'hr_shifts';

    protected $fillable = [];

    protected $casts = [
        'break_minutes' => 'integer',
        'late_grace_minutes' => 'integer',
        'early_leave_grace_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function isOvernight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /**
     * The shift's start and end on a work date, in the given timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(string $workDate, string $timezone): array
    {
        $start = CarbonImmutable::parse($workDate.' '.$this->start_time, $timezone);
        $end = CarbonImmutable::parse($workDate.' '.$this->end_time, $timezone);

        return [$start, $this->isOvernight() ? $end->addDay() : $end];
    }

    /**
     * Paid minutes: the window less the break.
     */
    public function scheduledMinutes(): int
    {
        [$start, $end] = $this->window('2000-01-03', 'UTC');

        return max(0, (int) $start->diffInMinutes($end) - $this->break_minutes);
    }
}
