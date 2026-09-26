<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Splits an amount across weighted parts by the largest-remainder method
 * at the currency's minor unit (spec §37.6 step 7): the parts always sum
 * to the amount exactly. Ties go to the lower key.
 */
final class MinorAllocator
{
    /**
     * @param  array<int, string>  $weights  key => non-negative weight (e.g. a line amount)
     * @return array<int, string> key => share, at Money::SCALE
     */
    public static function allocate(string $amount, array $weights, string $currency): array
    {
        $total = (string) Money::toMinor($amount, $currency);
        $scale = bcpow('10', (string) Money::decimals($currency));
        $minorWeights = array_map(static fn (string $w): string => bcmul(Money::round($w, $currency), $scale, 0), $weights);
        $sum = array_reduce($minorWeights, static fn (string $s, string $w): string => bcadd($s, $w, 0), '0');

        if (bccomp($sum, '0', 0) <= 0 || bccomp($total, '0', 0) <= 0) {
            return array_map(static fn (): string => Money::normalize(0), $weights);
        }

        $shares = [];
        $remainders = [];

        foreach ($minorWeights as $key => $weight) {
            $product = bcmul($total, $weight, 0);
            $shares[$key] = bcdiv($product, $sum, 0);
            $remainders[$key] = bcmod($product, $sum, 0);
        }

        $left = (int) bcsub($total, array_reduce($shares, static fn (string $s, string $v): string => bcadd($s, $v, 0), '0'), 0);
        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 0) ?: $a <=> $b);

        foreach (array_slice($order, 0, $left) as $key) {
            $shares[$key] = bcadd($shares[$key], '1', 0);
        }

        return array_map(static fn (string $minor): string => Money::fromMinor($minor, $currency), $shares);
    }
}
