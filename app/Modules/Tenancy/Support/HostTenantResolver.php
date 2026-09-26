<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * Resolves a request host to its tenant (spec §6.2, §7.5, §74).
 *
 * - Only hosts that may identify a tenant resolve: subdomains, and custom
 *   domains once DNS ownership is verified.
 * - Positive results are cached per host for 10 minutes as plain attributes
 *   (objects are never unserialised from the cache); unknown hosts are
 *   negatively cached for 60 seconds, so identification costs no landlord
 *   query on the hot path.
 */
final class HostTenantResolver extends DomainTenantResolver
{
    private const string MISSING = '__missing__';

    private const int POSITIVE_TTL = 600;

    private const int NEGATIVE_TTL = 60;

    private Repository $store;

    public function __construct(Factory $cache)
    {
        parent::__construct($cache);

        $this->store = $cache->store('landlord');
    }

    public function resolve(...$args): TenantContract
    {
        $host = strtolower((string) $args[0]);
        $key = self::cacheKeyFor($host);

        $cached = $this->store->get($key);

        if ($cached === self::MISSING) {
            throw new TenantCouldNotBeIdentifiedOnDomainException($host);
        }

        if (is_array($cached)) {
            /** @var Tenant $tenant */
            $tenant = (new Tenant)->newFromBuilder($cached);

            return $tenant;
        }

        try {
            $tenant = $this->resolveWithoutCache($host);
        } catch (TenantCouldNotBeIdentifiedOnDomainException $exception) {
            $this->store->put($key, self::MISSING, self::NEGATIVE_TTL);

            throw $exception;
        }

        $this->store->put($key, $tenant->getAttributes(), self::POSITIVE_TTL);

        return $tenant;
    }

    public function resolveWithoutCache(...$args): TenantContract
    {
        $host = strtolower((string) $args[0]);

        /** @var Domain|null $domain */
        $domain = Domain::query()->where('domain', $host)->first();

        if ($domain === null || ! $domain->identifiesTenant()) {
            throw new TenantCouldNotBeIdentifiedOnDomainException($host);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($domain->tenant_id);

        if ($tenant === null || $tenant->status === TenantStatus::Purged) {
            throw new TenantCouldNotBeIdentifiedOnDomainException($host);
        }

        self::$currentDomain = $domain;

        return $tenant;
    }

    public function invalidateCache(TenantContract $tenant): void
    {
        /** @var Tenant $tenant */
        foreach (Domain::query()->where('tenant_id', $tenant->getTenantKey())->pluck('domain') as $host) {
            $this->store->forget(self::cacheKeyFor((string) $host));
        }
    }

    public static function forgetHost(string $host): void
    {
        app(Factory::class)->store('landlord')->forget(self::cacheKeyFor(strtolower($host)));
    }

    public static function cacheKeyFor(string $host): string
    {
        return 'tenancy:host:'.$host;
    }
}
