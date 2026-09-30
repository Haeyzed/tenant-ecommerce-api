<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Links placed in emails point at the frontends, never at the API
 * (config app.frontend). The storefront is on the tenant's primary domain;
 * the store admin and seller portal on {slug}.admin.{root} (BG-02).
 */
final class FrontendUrl
{
    /**
     * @param  array<string, scalar>  $query
     */
    public static function platformAdmin(string $path, array $query = []): string
    {
        return self::build((string) config('app.frontend.platform_admin_url'), $path, $query);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public static function affiliatePortal(string $path, array $query = []): string
    {
        return self::build((string) config('app.frontend.affiliate_portal_url'), $path, $query);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public static function website(string $path, array $query = []): string
    {
        return self::build((string) config('app.frontend.website_url'), $path, $query);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public static function tenantAdmin(Tenant $tenant, string $path, array $query = []): string
    {
        $base = strtr((string) config('app.frontend.tenant_admin_url'), [
            '{slug}' => (string) $tenant->slug,
            '{root}' => (string) config('tenancy.root_domain'),
        ]);

        return self::build($base, $path, $query);
    }

    /**
     * The marketplace seller portal, served under /seller on the admin origin.
     *
     * @param  array<string, scalar>  $query
     */
    public static function sellerPortal(Tenant $tenant, string $path, array $query = []): string
    {
        return self::tenantAdmin($tenant, '/seller/'.ltrim($path, '/'), $query);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public static function storefront(Tenant $tenant, string $path, array $query = []): string
    {
        return self::build(self::tenantBase($tenant), $path, $query);
    }

    private static function tenantBase(Tenant $tenant): string
    {
        $domain = $tenant->primaryDomain()?->domain
            ?? $tenant->domains()->orderBy('id')->value('domain');

        return config('app.frontend.tenant_scheme').'://'.$domain;
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private static function build(string $base, string $path, array $query): string
    {
        $url = rtrim($base, '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
