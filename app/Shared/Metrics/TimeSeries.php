<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Aggregates a flow metric per date bucket with one GROUP BY query, so a
 * KPI's value and its sparkline come from the same rows (spec §22.7).
 *
 * Datetimes are stored in UTC. Buckets are in the range's timezone without
 * relying on MySQL's timezone tables: a zone with a fixed offset over the
 * range is grouped by local hour or day directly; a zone that changes
 * offset (daylight saving) is grouped by UTC hour and re-bucketed here.
 */
final class TimeSeries
{
    /**
     * Additive aggregate (COUNT or SUM) per group and bucket, with every
     * bucket present.
     *
     * @param  string  $aggregate  an additive SQL aggregate, e.g. "COUNT(*)" or "SUM(amount)"
     * @return array<string, array<string, string>> group => bucket => value
     */
    public static function aggregate(Builder $query, string $dateColumn, DateRange $range, string $aggregate, ?string $groupColumn = null): array
    {
        [$expression, $toBucket] = self::bucketing($dateColumn, $range);

        $rows = (clone $query)
            ->whereBetween($dateColumn, [$range->startUtc(), $range->endUtc()])
            ->selectRaw("{$expression} as bucket_key")
            ->selectRaw(($groupColumn ?? "''").' as group_key')
            ->selectRaw("{$aggregate} as aggregate_value")
            ->groupByRaw('bucket_key, group_key')
            ->get();

        $series = [];

        foreach ($rows as $row) {
            $bucket = $toBucket((string) $row->bucket_key);
            $group = (string) $row->group_key;
            $series[$group][$bucket] = bcadd($series[$group][$bucket] ?? '0', (string) ($row->aggregate_value ?? '0'), 4);
        }

        $buckets = array_fill_keys($range->buckets(), '0.0000');

        foreach ($series as $group => $points) {
            $series[$group] = array_intersect_key(array_replace($buckets, $points), $buckets);
        }

        return $series;
    }

    /**
     * An additive aggregate over the whole range per group, without
     * buckets (for comparison periods).
     *
     * @return array<string, string> group => value
     */
    public static function total(Builder $query, string $dateColumn, DateRange $range, string $aggregate, ?string $groupColumn = null): array
    {
        return (clone $query)
            ->whereBetween($dateColumn, [$range->startUtc(), $range->endUtc()])
            ->selectRaw(($groupColumn ?? "''").' as group_key')
            ->selectRaw("{$aggregate} as aggregate_value")
            ->groupByRaw('group_key')
            ->pluck('aggregate_value', 'group_key')
            ->map(static fn ($v): string => bcadd((string) ($v ?? '0'), '0', 4))
            ->all();
    }

    /**
     * @param  array<string, string>  $points
     */
    public static function sum(array $points): string
    {
        return array_reduce($points, static fn (string $carry, string $v): string => bcadd($carry, $v, 4), '0.0000');
    }

    /**
     * Sparkline points, at most 90 (§22.2): longer series are merged into
     * consecutive groups.
     *
     * @param  array<string, string>  $points
     * @return list<array{x: string, y: string|int}>
     */
    public static function sparkline(array $points, bool $asCount = false): array
    {
        $chunk = max(1, (int) ceil(count($points) / 90));
        $out = [];

        foreach (array_chunk($points, $chunk, true) as $group) {
            $sum = self::sum($group);
            $out[] = ['x' => (string) array_key_first($group), 'y' => $asCount ? (int) $sum : $sum];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $points
     * @return list<array{x: string, y: string|int}>
     */
    public static function points(array $points, bool $asCount = false): array
    {
        $out = [];

        foreach ($points as $x => $y) {
            $out[] = ['x' => (string) $x, 'y' => $asCount ? (int) $y : $y];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: \Closure(string): string}
     */
    private static function bucketing(string $column, DateRange $range): array
    {
        $zone = $range->zone();
        $offsetAtStart = $zone->getOffset($range->from);
        $transitions = $zone->getTransitions($range->from->getTimestamp(), $range->to->getTimestamp());
        $fixed = $transitions === false || count($transitions) <= 1;

        if ($fixed) {
            $minutes = intdiv($offsetAtStart, 60);
            $format = $range->interval === 'hour' ? '%Y-%m-%d %H' : '%Y-%m-%d';

            return [
                "DATE_FORMAT(DATE_ADD({$column}, INTERVAL {$minutes} MINUTE), '{$format}')",
                static fn (string $key): string => $range->bucketOf(CarbonImmutable::createFromFormat(
                    $range->interval === 'hour' ? 'Y-m-d H|' : 'Y-m-d|',
                    $key,
                    $range->timezone,
                ) ?: throw new \UnexpectedValueException("Bad bucket key [{$key}].")),
            ];
        }

        // Sub-hour part of the offset (e.g. +05:30), constant across DST
        // changes, so UTC-hour groups never straddle a local bucket.
        $shift = intdiv($offsetAtStart % 3600, 60);

        return [
            "DATE_FORMAT(DATE_ADD({$column}, INTERVAL {$shift} MINUTE), '%Y-%m-%d %H')",
            static fn (string $key): string => $range->bucketOf(
                (CarbonImmutable::createFromFormat('Y-m-d H|', $key, 'UTC') ?: throw new \UnexpectedValueException("Bad bucket key [{$key}]."))
                    ->subMinutes($shift)->setTimezone($range->timezone),
            ),
        ];
    }
}
