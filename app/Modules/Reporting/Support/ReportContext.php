<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\MetricsScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One report run (spec §61): the validated filters, the requester's staff
 * scope (§25.3), the read connection, the base currency and the tenant
 * timezone. Dates in filters are the tenant's calendar days.
 */
final readonly class ReportContext
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public array $filters,
        public MetricsScope $scope,
        public string $connection,
        public string $currency,
        public string $timezone,
    ) {}

    public function table(string $table): Builder
    {
        return DB::connection($this->connection)->table($table);
    }

    public function has(string $key): bool
    {
        return isset($this->filters[$key]) && $this->filters[$key] !== '';
    }

    public function int(string $key): ?int
    {
        return $this->has($key) ? (int) $this->filters[$key] : null;
    }

    public function string(string $key): ?string
    {
        return $this->has($key) ? (string) $this->filters[$key] : null;
    }

    /**
     * from and to (tenant days; default: the last 30 days, today included)
     * as a custom range.
     */
    public function range(string $interval = 'auto'): DateRange
    {
        $today = CarbonImmutable::now($this->timezone)->toDateString();

        return DateRange::fromInput([
            'range' => 'custom',
            'from' => $this->string('from') ?? CarbonImmutable::parse($today)->subDays(29)->toDateString(),
            'to' => $this->string('to') ?? $today,
            'compare' => 'none',
            'interval' => $interval,
        ], $this->timezone);
    }

    public function day(string $date): DateRange
    {
        return DateRange::fromInput(['range' => 'custom', 'from' => $date, 'to' => $date, 'compare' => 'none', 'interval' => 'day'], $this->timezone);
    }

    public function month(int $year, int $month): DateRange
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->timezone);

        return DateRange::fromInput(['range' => 'custom', 'from' => $start->toDateString(), 'to' => $start->endOfMonth()->toDateString(),
            'compare' => 'none', 'interval' => 'day'], $this->timezone);
    }

    public function today(): string
    {
        return CarbonImmutable::now($this->timezone)->toDateString();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC bounds of a range
     */
    public function between(DateRange $range): array
    {
        return [$range->startUtc(), $range->endUtc()];
    }

    public static function money(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    public static function quantity(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 3);
    }

    /**
     * a / b as a percentage with two places, or null when b is zero.
     */
    public static function percent(string $a, string $b): ?string
    {
        return bccomp($b, '0', 4) === 0 ? null : bcdiv(bcmul($a, '100', 6), $b, 2);
    }
}
