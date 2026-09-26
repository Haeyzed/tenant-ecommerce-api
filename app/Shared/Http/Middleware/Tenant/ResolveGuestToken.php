<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * guest.token (spec §38.2, §71). Reads X-Guest-Token and makes it available
 * as the "guest_token" request attribute. It never issues tokens.
 */
final class ResolveGuestToken
{
    public const string ATTRIBUTE = 'guest_token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->headers->get('X-Guest-Token', '');

        if (preg_match('/^[A-Za-z0-9\-]{32,64}$/', $token) === 1) {
            $request->attributes->set(self::ATTRIBUTE, $token);
        }

        return $next($request);
    }

    public static function from(Request $request): ?string
    {
        $token = $request->attributes->get(self::ATTRIBUTE);

        return is_string($token) ? $token : null;
    }
}
