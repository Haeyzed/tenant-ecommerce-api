<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use App\Shared\Exceptions\ApiException;

/**
 * Tenant-supplied URLs are called from our servers, so they must be public
 * HTTPS hosts: every address the host resolves to must be outside the
 * private, loopback, link-local and reserved ranges. Checked when the URL
 * is saved and again before every call (DNS can change); clients never
 * follow redirects.
 */
final class PublicUrlGuard
{
    /**
     * @return string the URL without a trailing slash
     */
    public static function assertPublic(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw ApiException::unprocessable('url_invalid', 'Use the store\'s https:// address.');
        }

        if ((bool) config('integrations.allow_private_hosts', false)) {
            return $url;
        }

        $literal = trim($host, '[]');
        $addresses = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : self::resolve($host);

        if ($addresses === []) {
            throw ApiException::unprocessable('url_unreachable', 'This address could not be found.');
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ApiException::unprocessable('url_not_public', 'This address is not a public internet address.');
            }
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (array $r): ?string => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
