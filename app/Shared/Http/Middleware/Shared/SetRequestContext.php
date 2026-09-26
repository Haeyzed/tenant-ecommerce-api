<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Shared;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns the request ID (spec §71, §77.7). Laravel's Context is shared with
 * the log context and serialised into every job dispatched during the
 * request, so one ID traces a checkout through its payment and posting jobs.
 */
final class SetRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');

        $requestId = preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        Context::add('request_id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
