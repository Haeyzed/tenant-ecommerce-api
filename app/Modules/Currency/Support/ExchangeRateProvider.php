<?php

declare(strict_types=1);

namespace App\Modules\Currency\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reference rates from the configured provider (config/currency.php,
 * UD-19). Returns 1 base = rate target for each requested target it knows;
 * an unknown provider, a failure or a missing pair returns nothing rather
 * than guessing.
 */
final class ExchangeRateProvider
{
    public const string OXR_CACHE_KEY = 'fx:openexchangerates:latest';

    public function configured(): bool
    {
        return match ((string) config('currency.fx_provider')) {
            'openexchangerates' => filled(config('currency.providers.openexchangerates.app_id')),
            'open_er_api' => true,
            default => false,
        };
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, string> target => rate
     */
    public function fetch(string $base, array $targets): array
    {
        return match ((string) config('currency.fx_provider')) {
            'openexchangerates' => $this->openExchangeRates(strtoupper($base), $targets),
            'open_er_api' => $this->openErApi(strtoupper($base), $targets),
            default => [],
        };
    }

    /**
     * USD-based rates (allowed on every plan) crossed to the base: 1 base =
     * usd[target] / usd[base] target. The response is shared by all tenants
     * through the landlord cache; a failed call is not cached.
     *
     * @param  list<string>  $targets
     * @return array<string, string>
     */
    private function openExchangeRates(string $base, array $targets): array
    {
        $config = (array) config('currency.providers.openexchangerates');

        $rates = Cache::store('landlord')->get(self::OXR_CACHE_KEY);

        if (! is_array($rates)) {
            try {
                $response = Http::baseUrl((string) $config['base_url'])
                    ->timeout((int) ($config['timeout'] ?? 10))
                    ->retry(2, 500, throw: false)
                    ->acceptJson()
                    ->get('/latest.json', ['app_id' => (string) $config['app_id']]);
            } catch (ConnectionException) {
                return [];
            }

            $rates = $response->json('rates');

            if (! $response->successful() || $response->json('base') !== 'USD' || ! is_array($rates)) {
                // The body can echo the request; only the status is logged.
                Log::warning('Open Exchange Rates request failed.', ['status' => $response->status()]);

                return [];
            }

            Cache::store('landlord')->put(self::OXR_CACHE_KEY, $rates, now()->addMinutes(max(1, (int) ($config['cache_minutes'] ?? 60))));
        }

        $baseRate = $rates[$base] ?? null;

        if (! is_numeric($baseRate) || (float) $baseRate <= 0) {
            return [];
        }

        $found = [];

        foreach ($targets as $target) {
            $rate = $rates[strtoupper($target)] ?? null;

            if (is_numeric($rate) && (float) $rate > 0) {
                $found[strtoupper($target)] = bcdiv(self::decimal($rate), self::decimal($baseRate), 12);
            }
        }

        return $found;
    }

    private static function decimal(int|float|string $value): string
    {
        return number_format((float) $value, 12, '.', '');
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, string>
     */
    private function openErApi(string $base, array $targets): array
    {
        $config = (array) config('currency.providers.open_er_api');

        try {
            $response = Http::baseUrl((string) $config['base_url'])
                ->timeout((int) ($config['timeout'] ?? 10))
                ->retry(2, 500, throw: false)
                ->acceptJson()
                ->get('/latest/'.rawurlencode($base));
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful() || $response->json('result') !== 'success' || $response->json('base_code') !== $base) {
            return [];
        }

        $rates = (array) $response->json('rates', []);
        $found = [];

        foreach ($targets as $target) {
            $rate = $rates[strtoupper($target)] ?? null;

            if (is_numeric($rate) && (float) $rate > 0) {
                $found[strtoupper($target)] = number_format((float) $rate, 12, '.', '');
            }
        }

        return $found;
    }
}
