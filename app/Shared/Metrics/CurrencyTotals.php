<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

/**
 * Money KPIs reported per currency, plus a combined total in the reporting
 * currency when a hand-maintained rate covers every currency present; the
 * combined total is then marked estimated (spec §22.5).
 */
final readonly class CurrencyTotals
{
    /**
     * @param  string  $reportingCurrency  platform default_currency
     * @param  array<string, string|float|int>  $rates  {currency: rate into the reporting currency}
     */
    public function __construct(
        private string $reportingCurrency,
        private array $rates,
    ) {}

    /**
     * @param  array<string, string>  $values  currency => amount
     * @param  array<string, string>|null  $previous  currency => amount, null without a comparison
     * @param  array<string, list<array{x: string, y: string|int}>>  $sparklines  currency => points
     * @return list<KpiValue>
     */
    public function kpis(string $key, string $label, array $values, DateRange $range, ?array $previous = null, string $polarity = KpiValue::UP_IS_GOOD, array $sparklines = [], ?string $supportingLabel = null): array
    {
        $currencies = array_keys($values + ($previous ?? []));
        sort($currencies);

        if ($range->currency !== null) {
            $currencies = [$range->currency];
        }

        if ($currencies === []) {
            $currencies = [$this->reportingCurrency];
        }

        $kpis = [];

        foreach ($currencies as $currency) {
            $kpis[] = KpiValue::money(
                $key, $label, $values[$currency] ?? '0', $currency, $range,
                $previous === null ? null : ($previous[$currency] ?? '0'),
                $polarity, $sparklines[$currency] ?? null, false, $supportingLabel,
            );
        }

        if ($range->currency === null && count($currencies) > 1) {
            $combined = $this->convert($values);
            $combinedPrevious = $previous === null ? null : $this->convert($previous);

            if ($combined !== null && ($previous === null || $combinedPrevious !== null)) {
                $kpis[] = KpiValue::money($key.'_combined', $label.' (all currencies)', $combined, $this->reportingCurrency, $range, $combinedPrevious, $polarity, null, true, $supportingLabel);
            }
        }

        return $kpis;
    }

    /**
     * The combined total in the reporting currency, or null when any
     * currency lacks a rate.
     *
     * @param  array<string, string>  $values
     */
    public function convert(array $values): ?string
    {
        $total = '0';

        foreach ($values as $currency => $amount) {
            if ($currency === $this->reportingCurrency) {
                $total = bcadd($total, $amount, 4);

                continue;
            }

            $rate = $this->rates[$currency] ?? null;

            if ($rate === null || ! is_numeric($rate) || (float) $rate <= 0) {
                return null;
            }

            // A JSON float such as 6.5E-4 is not a valid bcmath operand.
            $total = bcadd($total, bcmul($amount, is_string($rate) ? $rate : sprintf('%.10F', (float) $rate), 8), 4);
        }

        return $total;
    }

    public function reportingCurrency(): string
    {
        return $this->reportingCurrency;
    }
}
