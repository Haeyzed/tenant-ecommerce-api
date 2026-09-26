<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Decimal-string money arithmetic (bcmath, scale 4 as stored) and minor-unit
 * conversion for providers. Floats are never used for money.
 */
final class Money
{
    public const int SCALE = 4;

    /**
     * ISO 4217 currencies without minor units.
     *
     * @var list<string>
     */
    private const array ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function normalize(string|int|float $amount): string
    {
        return bcadd(is_float($amount) ? number_format($amount, self::SCALE, '.', '') : (string) $amount, '0', self::SCALE);
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

    public static function div(string $a, string $b): string
    {
        return bcdiv($a, $b, self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? $a : $b;
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }

    public static function isPositive(string $amount): bool
    {
        return self::cmp($amount, '0') > 0;
    }

    /**
     * Rounds half up to the currency's minor unit (2 decimals, or 0).
     */
    public static function round(string $amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        $offset = '0.'.str_repeat('0', $decimals).'5';
        $rounded = self::cmp($amount, '0') >= 0 ? bcadd($amount, $offset, $decimals) : bcsub($amount, $offset, $decimals);

        return bcadd($rounded, '0', self::SCALE);
    }

    public static function toMinor(string $amount, string $currency): int
    {
        return (int) bcmul(self::round($amount, $currency), bcpow('10', (string) self::decimals($currency)), 0);
    }

    public static function fromMinor(int|string $minor, string $currency): string
    {
        return bcdiv((string) $minor, bcpow('10', (string) self::decimals($currency)), self::SCALE);
    }

    /**
     * Display form for messages, e.g. "USD 1,250.00". Never parsed back.
     */
    public static function format(string $amount, string $currency): string
    {
        $rounded = self::round($amount, $currency);
        $negative = str_starts_with($rounded, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($rounded, '-')), 2, '');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));
        $decimals = self::decimals($currency);

        return strtoupper($currency).' '.($negative ? '-' : '').$grouped.($decimals > 0 ? '.'.substr(str_pad($fraction, $decimals, '0'), 0, $decimals) : '');
    }

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }
}
