<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Trusted proxies (BG-01, spec §75): the edge and the frontend servers
 * forward the visitor's address, so rate limits, legal-acceptance evidence
 * and fraud checks see the real client. Only the client address and scheme
 * are trusted; the host never is, because the tenant is resolved from the
 * Host header the proxy sends (a forged X-Forwarded-Host must not select a
 * tenant).
 */
final class TrustedProxies
{
    public const int HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    public static function configure(): void
    {
        TrustProxies::withHeaders(self::HEADERS);

        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) config('app.trusted_proxies')))));

        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }
    }
}
