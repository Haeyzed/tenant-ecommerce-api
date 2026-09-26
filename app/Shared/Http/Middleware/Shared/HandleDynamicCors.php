<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Shared;

use App\Modules\Tenancy\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * cors.dynamic (spec §70.9). Allowed origins are the platform admin frontend
 * origins, https origins on landlord domains and tenant subdomains, and
 * verified custom domains. Never a wildcard; credentials are not supported
 * because every actor uses bearer tokens.
 */
final class HandleDynamicCors
{
    private const string ALLOWED_HEADERS = 'Authorization, Content-Type, Accept, X-Guest-Token, Idempotency-Key, X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null || ! $this->isAllowed($origin)) {
            if ($request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')) {
                return new IlluminateResponse('', 204);
            }

            return $next($request);
        }

        if ($request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')) {
            return $this->withHeaders(new IlluminateResponse('', 204), $origin, true);
        }

        /** @var Response $response */
        $response = $next($request);

        return $this->withHeaders($response, $origin, false);
    }

    private function isAllowed(string $origin): bool
    {
        $admin = array_filter(array_map('trim', explode(',', (string) config('app.platform_admin_origins'))));

        if (in_array($origin, $admin, true)) {
            return true;
        }

        $parts = parse_url($origin);
        $scheme = $parts['scheme'] ?? null;
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || ($scheme !== 'https' && ! app()->environment('local', 'testing'))) {
            return false;
        }

        $root = (string) config('tenancy.root_domain');

        if (in_array($host, (array) config('tenancy.central_domains'), true)
            || ($root !== '' && str_ends_with($host, '.'.$root))) {
            return true;
        }

        return (bool) Cache::store('landlord')->remember('cors:origin:'.$host, 600, function () use ($host): bool {
            /** @var Domain|null $domain */
            $domain = Domain::query()->where('domain', $host)->first();

            return $domain !== null && $domain->identifiesTenant();
        });
    }

    private function withHeaders(Response $response, string $origin, bool $preflight): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin', false);
        $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id, Idempotent-Replayed, Retry-After, X-Module-Notice');

        if ($preflight) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        return $response;
    }
}
