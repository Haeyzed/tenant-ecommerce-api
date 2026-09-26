<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Support;

use App\Modules\Promotions\Models\Promotion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The per-tenant cache of active automatic promotions with their targets
 * (spec §37.6 step 1, §74). The default cache store is tenant-prefixed in
 * the tenant context. Every promotion or target change flushes it; the
 * engine still checks each window at evaluation time.
 */
final class PromotionCache
{
    private const string KEY = 'promotions:automatic';

    private const int TTL_SECONDS = 600;

    /**
     * @return Collection<int, Promotion>
     */
    public function automatic(): Collection
    {
        /** @var Collection<int, Promotion> */
        return Cache::remember(self::KEY, self::TTL_SECONDS, static fn (): Collection => Promotion::query()
            ->with('targets')
            ->where('trigger', Promotion::AUTOMATIC)
            ->where('is_active', true)
            ->where(static fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('id')
            ->get());
    }

    public function flush(): void
    {
        Cache::forget(self::KEY);
    }
}
