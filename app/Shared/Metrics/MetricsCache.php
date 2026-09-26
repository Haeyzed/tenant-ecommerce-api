<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache of dashboard sections and contextual KPIs (spec
 * §22.7): per context, section, permission set and parameters. Dashboards
 * are summaries, so a short TTL replaces cache busting on every write.
 */
final class MetricsCache
{
    public const int SECTION_TTL = 300;

    public const int KPI_TTL = 120;

    public const int CLOSED_RANGE_TTL = 3600;

    /**
     * @template T of array
     *
     * @param  list<string>  $permissions  the viewer's permission names that shape the payload
     * @param  Closure(): T  $compute
     * @return array{data: T, cached: bool, generated_at: string}
     */
    public function remember(string $kind, string $name, DateRange $range, array $permissions, Closure $compute): array
    {
        sort($permissions);

        $key = implode(':', [
            'metrics',
            $kind,
            $name,
            sha1($range->cacheKey().'|'.implode(',', $permissions)),
        ]);

        $ttl = $range->isClosed() ? self::CLOSED_RANGE_TTL : ($kind === 'section' ? self::SECTION_TTL : self::KPI_TTL);

        $hit = $this->store()->get($key);

        if (is_array($hit)) {
            return ['data' => $hit['data'], 'cached' => true, 'generated_at' => $hit['generated_at']];
        }

        $entry = ['data' => $compute(), 'generated_at' => now()->toIso8601String()];
        $this->store()->put($key, $entry, $ttl);

        return [...$entry, 'cached' => false];
    }

    /**
     * Landlord figures use the landlord store; inside a tenant the default
     * store is tenant-prefixed (§6.3).
     */
    private function store(): Repository
    {
        return tenancy()->initialized ? Cache::store() : Cache::store('landlord');
    }
}
