<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day's value of a platform stock metric whose history cannot be
 * rebuilt from event rows (spec §22.6). Written once a day by
 * RecordPlatformDailyMetrics; never updated by hand.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $metric
 * @property string $dimension
 * @property string $value
 */
class PlatformDailyMetric extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'landlord';

    protected $fillable = ['date', 'metric', 'dimension', 'value'];

    protected $casts = [
        'date' => 'date',
        'value' => 'decimal:4',
    ];

    /**
     * A metric's values on one date, or null when no snapshot exists for
     * it (the comparison is then omitted, never invented).
     *
     * @return array<string, string>|null dimension => value
     */
    public static function valuesOn(string $metric, CarbonInterface $date): ?array
    {
        $values = self::query()
            ->where('metric', $metric)
            ->where('date', $date->toDateString())
            ->pluck('value', 'dimension')
            ->map(static fn ($v): string => (string) $v)
            ->all();

        return $values === [] ? null : $values;
    }

    /**
     * Daily values of a metric over a date range.
     *
     * @return array<string, array<string, string>> dimension => date => value
     */
    public static function history(string $metric, CarbonInterface $from, CarbonInterface $to): array
    {
        $history = [];

        self::query()
            ->where('metric', $metric)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get(['date', 'dimension', 'value'])
            ->each(static function (self $row) use (&$history): void {
                $history[$row->dimension][$row->date->toDateString()] = (string) $row->value;
            });

        return $history;
    }
}
