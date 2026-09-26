<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use App\Modules\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tenant.webhooks (spec §15.6): initialises the tenant named by the
 * {tenant} path segment of /api/webhooks/{tenant}/{provider}/{mode} on a
 * landlord domain. stancl's path middleware cannot be used: the landlord
 * routes carry a {landlord_domain} parameter first. The parameter is then
 * removed, so controllers receive provider and mode only.
 */
final class InitializeTenancyFromWebhookPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $id = (string) $route?->parameter('tenant');

        $tenant = $id === '' ? null : Tenant::query()->whereKey($id)->whereNotNull('provisioned_at')->whereNull('purged_at')->first();

        if ($tenant === null) {
            abort(404);
        }

        $route->forgetParameter('tenant');
        tenancy()->initialize($tenant);

        return $next($request);
    }
}
