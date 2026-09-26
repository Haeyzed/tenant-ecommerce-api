<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * A resolved dashboard date range (spec §22.1): the range and its comparison
 * in the context's business timezone, and the bucket interval. The bounds
 * are inclusive instants; queries use their UTC equivalents.
 */
final readonly class DateRange
{
    public const array PRESETS = [
        'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month',
        'this_quarter', 'this_year', 'last_year', 'custom',
    ];

    public const array COMPARES = ['previous_period', 'previous_year', 'none'];

    public const array INTERVALS = ['auto', 'hour', 'day', 'week', 'month'];

    public const int MAX_CUSTOM_DAYS = 731;

    /**
     * A chart never has more buckets than this; a finer interval asked for
     * a long range is coarsened.
     */
    public const int MAX_BUCKETS = 400;

    private const array ORDER = ['hour', 'day', 'week', 'month'];

    /**
     * Presets that end "now": their comparison covers the same elapsed
     * length, so the 10th of this month compares with the first ten days
     * of the previous month (§22.1).
     */
    private const array TO_DATE = ['today', 'this_month', 'this_quarter', 'this_year'];

    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public string $interval,
        public string $compare,
        public ?CarbonImmutable $comparisonFrom,
        public ?CarbonImmutable $comparisonTo,
        public ?string $currency = null,
    ) {}

    /**
     * @param  array{range?: string|null, from?: string|null, to?: string|null, compare?: string|null, interval?: string|null, currency?: string|null}  $input  validated input
     */
    public static function fromInput(array $input, string $timezone, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $preset = $input['range'] ?? 'last_30_days';
        $today = $now->startOfDay();

        [$from, $to] = match ($preset) {
            'today' => [$today, $now],
            'yesterday' => [$today->subDay(), $today->subDay()->endOfDay()],
            'last_7_days' => [$today->subDays(7), $today->subDay()->endOfDay()],
            'last_30_days' => [$today->subDays(30), $today->subDay()->endOfDay()],
            'this_month' => [$today->startOfMonth(), $now],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$today->startOfQuarter(), $now],
            'this_year' => [$today->startOfYear(), $now],
            'last_year' => [$today->subYearNoOverflow()->startOfYear(), $today->subYearNoOverflow()->endOfYear()],
            'custom' => [
                CarbonImmutable::parse((string) $input['from'], $timezone)->startOfDay(),
                CarbonImmutable::parse((string) $input['to'], $timezone)->endOfDay(),
            ],
        };

        $compare = $input['compare'] ?? 'previous_period';
        [$cFrom, $cTo] = self::comparisonFor($preset, $compare, $from, $to);

        return new self(
            $preset,
            $from,
            $to,
            $timezone,
            self::resolveInterval($input['interval'] ?? 'auto', $from, $to),
            $compare,
            $cFrom,
            $cTo,
            isset($input['currency']) ? strtoupper((string) $input['currency']) : null,
        );
    }

    /**
     * The range and interval a daily-history chart covers when a caller
     * has no request (for example the daily snapshot job).
     */
    public static function days(CarbonImmutable $from, CarbonImmutable $to, string $timezone): self
    {
        $from = $from->setTimezone($timezone)->startOfDay();
        $to = $to->setTimezone($timezone)->endOfDay();

        return new self('custom', $from, $to, $timezone, 'day', 'none', null, null);
    }

    public function hasComparison(): bool
    {
        return $this->comparisonFrom !== null;
    }

    public function comparison(): ?self
    {
        if ($this->comparisonFrom === null || $this->comparisonTo === null) {
            return null;
        }

        return new self($this->preset, $this->comparisonFrom, $this->comparisonTo, $this->timezone, $this->interval, 'none', null, null, $this->currency);
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    /**
     * Whether the range ended before today, so its figures no longer
     * change (apart from late refunds) and may be cached longer (§22.7).
     */
    public function isClosed(): bool
    {
        return $this->to->lt(CarbonImmutable::now($this->timezone)->startOfDay());
    }

    public function supportingLabel(): ?string
    {
        if ($this->comparisonFrom === null) {
            return null;
        }

        if ($this->compare === 'previous_year') {
            return 'vs same period last year';
        }

        $days = (int) round($this->from->diffInDays($this->to->addSecond()));

        return match ($this->preset) {
            'today' => 'vs same time yesterday',
            'yesterday' => 'vs previous day',
            'this_month' => 'vs same days last month',
            'last_month' => 'vs previous month',
            'this_quarter' => 'vs same days last quarter',
            'this_year' => 'vs same days last year',
            'last_year' => 'vs previous year',
            default => 'vs previous '.max(1, $days).' days',
        };
    }

    /**
     * @return array{preset: string, from: string, to: string, timezone: string, interval: string, compare: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'timezone' => $this->timezone,
            'interval' => $this->interval,
            'compare' => $this->compare,
        ];
    }

    /**
     * @return array{from: string, to: string}|null
     */
    public function comparisonToArray(): ?array
    {
        return $this->comparisonFrom === null || $this->comparisonTo === null ? null : [
            'from' => $this->comparisonFrom->toDateString(),
            'to' => $this->comparisonTo->toDateString(),
        ];
    }

    /**
     * Stable identity of the parameters, for cache keys.
     */
    public function cacheKey(): string
    {
        return implode('|', [
            $this->preset, $this->from->toIso8601String(), $this->preset === 'custom' || ! in_array($this->preset, self::TO_DATE, true)
                ? $this->to->toIso8601String()
                : $this->to->format('Y-m-d H'), // to-date ranges move with "now"; the TTL bounds staleness
            $this->timezone, $this->interval, $this->compare, (string) $this->currency,
        ]);
    }

    /**
     * The bucket a local time falls into.
     */
    public function bucketOf(CarbonImmutable $local): string
    {
        return match ($this->interval) {
            'hour' => $local->format('Y-m-d H:00'),
            'day' => $local->toDateString(),
            'week' => $local->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            default => $local->format('Y-m'),
        };
    }

    /**
     * Every bucket of the range, in order, so charts show zero-valued
     * buckets instead of gaps.
     *
     * @return list<string>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor = match ($this->interval) {
            'hour' => $this->from->startOfHour(),
            'day' => $this->from->startOfDay(),
            'week' => $this->from->startOfWeek(CarbonImmutable::MONDAY),
            default => $this->from->startOfMonth(),
        };

        while ($cursor->lte($this->to)) {
            $buckets[] = $this->bucketOf($cursor);
            $cursor = match ($this->interval) {
                'hour' => $cursor->addHour(),
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        return $buckets;
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function comparisonFor(string $preset, string $compare, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($compare === 'none') {
            return [null, null];
        }

        if ($compare === 'previous_year') {
            return [$from->subYearNoOverflow(), $to->subYearNoOverflow()];
        }

        $elapsed = $from->diffInSeconds($to);

        return match ($preset) {
            'today' => [$from->subDay(), $from->subDay()->addSeconds((int) $elapsed)],
            'this_month' => self::toDate($from->subMonthNoOverflow(), $from->subMonthNoOverflow()->endOfMonth(), $elapsed),
            'this_quarter' => self::toDate($from->subMonthsNoOverflow(3), $from->subMonthsNoOverflow(3)->endOfQuarter(), $elapsed),
            'this_year' => self::toDate($from->subYearNoOverflow(), $from->subYearNoOverflow()->endOfYear(), $elapsed),
            'last_month' => [$from->subMonthNoOverflow()->startOfMonth(), $from->subMonthNoOverflow()->endOfMonth()],
            'last_year' => [$from->subYearNoOverflow()->startOfYear(), $from->subYearNoOverflow()->endOfYear()],
            default => (static function () use ($from, $to): array {
                $days = (int) round($from->diffInDays($to->addSecond()));

                return [$from->subDays($days), $from->subSecond()];
            })(),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function toDate(CarbonImmutable $start, CarbonImmutable $periodEnd, float $elapsed): array
    {
        $end = $start->addSeconds((int) $elapsed);

        return [$start, $end->gt($periodEnd) ? $periodEnd : $end];
    }

    private static function resolveInterval(string $interval, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = $from->diffInDays($to);

        if ($interval === 'auto') {
            $interval = match (true) {
                $days <= 2 => 'hour',
                $days <= 62 => 'day',
                $days <= 182 => 'week',
                default => 'month',
            };
        }

        $perBucket = ['hour' => 1 / 24, 'day' => 1, 'week' => 7, 'month' => 30];
        $index = array_search($interval, self::ORDER, true);

        while ($index < count(self::ORDER) - 1 && $days / $perBucket[self::ORDER[$index]] > self::MAX_BUCKETS) {
            $index++;
        }

        return self::ORDER[$index];
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }
}
