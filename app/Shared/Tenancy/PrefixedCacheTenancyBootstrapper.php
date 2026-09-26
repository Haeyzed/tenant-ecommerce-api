<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Isolates the default cache store per tenant by key prefix rather than
 * tags, because tags do not scale on Redis and are unsupported by the
 * database store (spec §6.3, §74). The "landlord" store is never touched.
 */
final class PrefixedCacheTenancyBootstrapper implements TenancyBootstrapper
{
    private ?string $originalPrefix = null;

    public function __construct(
        private readonly Config $config,
        private readonly CacheManager $cache,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalPrefix ??= (string) $this->config->get('cache.prefix');

        $this->config->set('cache.prefix', $this->originalPrefix.'tenant_'.$tenant->getTenantKey().'_');

        $this->resetStores();
    }

    public function revert(): void
    {
        if ($this->originalPrefix === null) {
            return;
        }

        $this->config->set('cache.prefix', $this->originalPrefix);
        $this->originalPrefix = null;

        $this->resetStores();
    }

    private function resetStores(): void
    {
        foreach (array_keys((array) $this->config->get('cache.stores')) as $store) {
            if ($store !== 'landlord') {
                $this->cache->forgetDriver($store);
            }
        }

        Cache::clearResolvedInstances();
    }
}
