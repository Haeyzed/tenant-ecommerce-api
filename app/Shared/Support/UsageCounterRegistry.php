<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Closure;
use InvalidArgumentException;

/**
 * Live usage counters per count and storage limit key (spec §11.10). Each
 * counter runs in the tenant context and returns the current usage. Code
 * modules register their counter when they own the counted table; the
 * architecture tests require one for every count and storage key.
 */
final class UsageCounterRegistry
{
    /** @var array<string, Closure(): int> */
    private array $counters = [];

    /**
     * @param  Closure(): int  $counter
     */
    public function register(string $limitKey, Closure $counter): void
    {
        if (! array_key_exists($limitKey, (array) config('limits'))) {
            throw new InvalidArgumentException("Unknown limit key [{$limitKey}].");
        }

        $this->counters[$limitKey] = $counter;
    }

    public function has(string $limitKey): bool
    {
        return isset($this->counters[$limitKey]);
    }

    /**
     * Current usage; must be called in the tenant context.
     */
    public function count(string $limitKey): int
    {
        $counter = $this->counters[$limitKey] ?? throw new InvalidArgumentException("No usage counter for [{$limitKey}].");

        return $counter();
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->counters);
    }
}
