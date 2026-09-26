<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Landlord;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Landlord routes are constrained to the landlord domains by a domain
 * parameter (spec §70.2). The parameter is removed here so it is never passed
 * to a controller.
 */
final class EnsureLandlordDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->route()?->forgetParameter('landlord_domain');

        return $next($request);
    }
}
