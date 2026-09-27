<?php

declare(strict_types=1);

namespace App\Modules\Currency\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reference rates from the configured provider (config/currency.php,
 * UD-19). Returns 1 base = rate target for each requested target it knows;
 * an unknown provider, a failure or a missing pair returns nothing rather
 * than guessing.
 */
final class ExchangeRateProvider
{
    public function configured(): bool
    {
        return (string) config('currency.fx_provider') !== 'none';
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, string> target => rate
     */
    public function fetch(string $base, array $targets): array
    {
        return match ((string) config('currency.fx_provider')) {
            'open_er_api' => $this->openErApi(strtoupper($base), $targets),
            default => [],
        };
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
