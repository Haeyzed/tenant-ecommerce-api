<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * DNS lookups for custom domain verification (spec §7.5). A seam so the
 * checks can be exercised without real DNS.
 */
class DnsResolver
{
    /**
     * @return list<string>
     */
    public function txt(string $host): array
    {
        return array_values(array_map(
            static fn (array $record): string => (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? []))),
            $this->lookup($host, DNS_TXT),
        ));
    }

    public function cname(string $host): ?string
    {
        $records = $this->lookup($host, DNS_CNAME);

        return isset($records[0]['target']) ? strtolower(rtrim((string) $records[0]['target'], '.')) : null;
    }

    /**
     * @return list<string>
     */
    public function a(string $host): array
    {
        return array_values(array_map(static fn (array $r): string => (string) $r['ip'], $this->lookup($host, DNS_A)));
    }

    /**
     * @return list<string>
     */
    public function aaaa(string $host): array
    {
        return array_values(array_map(static fn (array $r): string => (string) $r['ipv6'], $this->lookup($host, DNS_AAAA)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lookup(string $host, int $type): array
    {
        $records = @dns_get_record($host, $type);

        return is_array($records) ? array_values($records) : [];
    }
}
