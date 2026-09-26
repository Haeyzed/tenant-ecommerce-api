<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Decimal-string stock quantity arithmetic (bcmath, scale 3 as stored in
 * the qty columns). Floats are never used for stock.
 */
final class Quantity
{
    public const int SCALE = 3;

    public static function normalize(string|int|float $quantity): string
    {
        return bcadd(is_float($quantity) ? number_format($quantity, self::SCALE, '.', '') : (string) $quantity, '0', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function neg(string $a): string
    {
        return bcsub('0', $a, self::SCALE);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }

    public static function isPositive(string $quantity): bool
    {
        return self::cmp($quantity, '0') > 0;
    }

    /**
     * Whole units of $unit that fit in $available (bundle availability).
     */
    public static function wholeUnits(string $available, string $unit): string
    {
        if (! self::isPositive($unit) || ! self::isPositive($available)) {
            return self::normalize(0);
        }

        return self::normalize(bcdiv($available, $unit, 0));
    }
}
