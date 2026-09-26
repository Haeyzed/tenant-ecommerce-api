<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use JsonSerializable;

/**
 * One KPI card (spec §22.2). Money values are decimal strings with a
 * currency, counts are integers, percentages strings with one decimal.
 * A value is null only when it is undefined (for example a rate whose
 * denominator is zero), never zero-filled.
 */
final readonly class KpiValue implements JsonSerializable
{
    public const string MONEY = 'money';

    public const string COUNT = 'count';

    public const string PERCENT = 'percent';

    public const string DURATION = 'duration_seconds';

    public const string RATIO = 'ratio';

    /** A rise is good (sales), a fall is good (churn), or neither. */
    public const string UP_IS_GOOD = 'positive';

    public const string DOWN_IS_GOOD = 'negative';

    public const string NEUTRAL = 'neutral';

    /**
     * @param  list<array{x: string, y: string|int}>|null  $sparkline
     * @param  array{value: string|int|null, from: string, to: string, change_percent: string|null, direction: string, sentiment: string}|null  $comparison
     */
    public function __construct(
        public string $key,
        public string $label,
        public string|int|null $value,
        public string $format,
        public ?string $currencyCode = null,
        public ?array $comparison = null,
        public ?string $supportingLabel = null,
        public ?array $sparkline = null,
        public bool $isEstimated = false,
    ) {}

    /**
     * @param  list<array{x: string, y: string|int}>|null  $sparkline
     */
    public static function count(string $key, string $label, int $value, DateRange $range, ?int $previous = null, string $polarity = self::UP_IS_GOOD, ?array $sparkline = null): self
    {
        return new self(
            $key, $label, $value, self::COUNT, null,
            self::compare((string) $value, $previous === null ? null : (string) $previous, $range, $polarity, static fn (string $v): int => (int) $v),
            $previous === null ? null : $range->supportingLabel(),
            $sparkline,
        );
    }

    /**
     * @param  list<array{x: string, y: string|int}>|null  $sparkline
     */
    public static function money(string $key, string $label, string $value, string $currency, DateRange $range, ?string $previous = null, string $polarity = self::UP_IS_GOOD, ?array $sparkline = null, bool $estimated = false, ?string $supportingLabel = null): self
    {
        return new self(
            $key, $label, bcadd($value, '0', 4), self::MONEY, $currency,
            self::compare($value, $previous, $range, $polarity, static fn (string $v): string => bcadd($v, '0', 4)),
            $supportingLabel ?? ($previous === null ? null : $range->supportingLabel()),
            $sparkline,
            $estimated,
        );
    }

    /**
     * A percentage from a numerator and denominator; null when the
     * denominator is zero.
     */
    public static function rate(string $key, string $label, string $numerator, string $denominator, DateRange $range, ?array $previous = null, string $polarity = self::UP_IS_GOOD): self
    {
        $value = self::percentOf($numerator, $denominator);
        $prev = $previous === null ? null : self::percentOf($previous[0], $previous[1]);

        return new self(
            $key, $label, $value, self::PERCENT, null,
            $value === null || $previous === null ? null : self::compare($value, $prev, $range, $polarity, static fn (string $v): string => $v),
            $previous === null ? null : $range->supportingLabel(),
        );
    }

    public static function percentOf(string $numerator, string $denominator): ?string
    {
        if (bccomp($denominator, '0', 4) === 0) {
            return null;
        }

        return self::oneDecimal(bcdiv(bcmul($numerator, '100', 8), $denominator, 8));
    }

    /**
     * @param  \Closure(string): (string|int)  $cast
     * @return array{value: string|int|null, from: string, to: string, change_percent: string|null, direction: string, sentiment: string}|null
     */
    private static function compare(string $current, ?string $previous, DateRange $range, string $polarity, \Closure $cast): ?array
    {
        $comparison = $range->comparisonToArray();

        if ($comparison === null || $previous === null) {
            return null;
        }

        $change = bccomp($previous, '0', 8) === 0
            ? null
            : bcdiv(bcmul(bcsub($current, $previous, 8), '100', 8), ltrim($previous, '-'), 8);

        $direction = match (true) {
            $change !== null && bccomp(ltrim($change, '-'), '0.05', 8) < 0 => 'flat',
            bccomp($current, $previous, 8) > 0 => 'up',
            bccomp($current, $previous, 8) < 0 => 'down',
            default => 'flat',
        };

        $sentiment = match (true) {
            $polarity === self::NEUTRAL || $direction === 'flat' => 'neutral',
            ($direction === 'up') === ($polarity === self::UP_IS_GOOD) => 'positive',
            default => 'negative',
        };

        return [
            'value' => $cast($previous),
            'from' => $comparison['from'],
            'to' => $comparison['to'],
            'change_percent' => $change === null ? null : self::oneDecimal($change),
            'direction' => $direction,
            'sentiment' => $sentiment,
        ];
    }

    private static function oneDecimal(string $value): string
    {
        // Half away from zero, then one decimal.
        $adjust = str_starts_with($value, '-') ? '-0.05' : '0.05';

        return bcadd(bcadd($value, $adjust, 8), '0', 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'format' => $this->format,
            'currency_code' => $this->currencyCode,
            'is_estimated' => $this->isEstimated,
            'comparison' => $this->comparison,
            'supporting_label' => $this->supportingLabel,
            'sparkline' => $this->sparkline,
        ];
    }
}
