<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Landlord;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * internal.edge (spec §71): only source IPs in EDGE_ALLOWED_IPS presenting
 * the EDGE_SHARED_SECRET in X-Edge-Secret, compared in constant time.
 * Anything else is 404, so the endpoint does not reveal itself.
 */
final class AuthenticateEdgeRequest
{
    public const string HEADER = 'X-Edge-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('app.edge_shared_secret');
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) config('app.edge_allowed_ips')))));
        $given = (string) $request->headers->get(self::HEADER, '');

        if ($secret === '' || $allowed === [] || ! IpUtils::checkIp((string) $request->ip(), $allowed) || ! hash_equals($secret, $given)) {
            abort(404);
        }

        return $next($request);
    }
}
