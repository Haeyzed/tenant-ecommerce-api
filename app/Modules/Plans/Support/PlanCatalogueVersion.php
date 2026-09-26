<?php

declare(strict_types=1);

namespace App\Modules\Plans\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A counter in every per-tenant entitlement and limit cache key. Editing a
 * plan's features or limits bumps it, so the change reaches every tenant on
 * the plan at once instead of after the cache TTL.
 */
final class PlanCatalogueVersion
{
    private const string KEY = 'plans:catalogue-version';

    public static function current(): int
    {
        return (int) Cache::store('landlord')->rememberForever(self::KEY, static fn (): int => 1);
    }

    public static function bump(): void
    {
        $store = Cache::store('landlord');

        if ($store->get(self::KEY) === null) {
            $store->forever(self::KEY, 2);

            return;
        }

        $store->increment(self::KEY);
    }
}
