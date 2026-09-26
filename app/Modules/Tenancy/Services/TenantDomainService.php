<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\DomainStatus;
use App\Modules\Tenancy\Jobs\CheckCustomDomainTls;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\DnsResolver;
use App\Modules\Tenancy\Support\TlsProbe;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant domains (spec §7.5). Landlord service called from the tenant
 * context under §6.4; operational state is written only by the checks.
 */
final readonly class TenantDomainService
{
    private const int TLS_ATTEMPTS = 6;

    private const int CERTIFICATE_WARNING_DAYS = 14;

    /**
     * Second-level labels under which the registrable (apex) domain has
     * three labels, e.g. example.co.uk.
     *
     * @var list<string>
     */
    private const array SECOND_LEVEL = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ltd', 'plc', 'sch', 'me'];

    public function __construct(
        private PlatformSettingsService $settings,
        private DnsResolver $dns,
        private TlsProbe $tls,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * @return Collection<int, Domain>
     */
    public function listDomains(Tenant $tenant): Collection
    {
        return Domain::query()->where('tenant_id', $tenant->getTenantKey())->orderByDesc('is_primary')->orderBy('id')->get()->toBase();
    }

    public function addCustomDomain(Tenant $tenant, string $host): Domain
    {
        if (! (bool) $this->settings->get('custom_domains_enabled', false)) {
            throw ApiException::forbidden('custom_domains_disabled', 'Custom domains are not available.');
        }

        $host = strtolower(rtrim(trim($host), '.'));
        $root = strtolower((string) config('tenancy.root_domain'));
        $central = array_map('strtolower', (array) config('tenancy.central_domains'));

        $invalid = ! preg_match('/^(?=.{4,253}$)((?!-)[a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/', $host)
            || in_array($host, $central, true)
            || $host === $root
            || Str::endsWith($host, '.'.$root);

        if ($invalid) {
            throw ValidationException::withMessages(['domain' => ['Enter a domain you own, outside the platform domain.']]);
        }

        if (Domain::query()->where('domain', $host)->exists()) {
            throw ValidationException::withMessages(['domain' => ['This domain is already in use.']]);
        }

        /** @var Domain $domain */
        $domain = Domain::query()->create([
            'domain' => $host,
            'tenant_id' => $tenant->getTenantKey(),
            'type' => 'custom',
            'is_primary' => false,
            'status' => DomainStatus::PendingVerification,
            'verification_token' => Str::random(40),
        ]);

        return $domain;
    }

    /**
     * Computed on every read from the current platform settings (rule 4).
     *
     * @return list<array{type: string, host: string, value: string}>
     */
    public function dnsInstructions(Domain $domain): array
    {
        if (! $domain->isCustom()) {
            return [];
        }

        $records = [[
            'type' => 'TXT',
            'host' => $this->txtHost($domain),
            'value' => (string) $domain->verification_token,
        ]];

        if ($this->isApex($domain->domain)) {
            foreach ((array) $this->settings->get('custom_domain_ipv4_addresses', []) as $ip) {
                $records[] = ['type' => 'A', 'host' => $domain->domain, 'value' => (string) $ip];
            }

            foreach ((array) $this->settings->get('custom_domain_ipv6_addresses', []) as $ip) {
                $records[] = ['type' => 'AAAA', 'host' => $domain->domain, 'value' => (string) $ip];
            }
        } else {
            $records[] = ['type' => 'CNAME', 'host' => $domain->domain, 'value' => (string) $this->settings->get('custom_domain_cname_target')];
        }

        return $records;
    }

    /**
     * Ownership and routing check for a pending domain.
     */
    public function verify(Domain $domain): Domain
    {
        if (! $domain->isCustom() || $domain->status !== DomainStatus::PendingVerification) {
            return $domain;
        }

        $ownership = in_array((string) $domain->verification_token, $this->dns->txt($this->txtHost($domain)), true);
        [$routingType, $observed] = $this->routing($domain->domain);

        $domain->forceFill([
            'last_dns_check_at' => now(),
            'dns_check_result' => $observed + ['txt_verified' => $ownership],
        ]);

        if ($ownership && $routingType !== null) {
            $domain->forceFill([
                'status' => DomainStatus::Verified,
                'verified_at' => now(),
                'routing_type' => $routingType,
                'tls_status' => 'pending',
                'failure_reason' => null,
            ])->save();

            CheckCustomDomainTls::dispatch($domain->id);

            return $domain;
        }

        $windowHours = (int) $this->settings->get('custom_domain_verification_window_hours', 72);

        $domain->forceFill([
            'failure_reason' => $ownership ? 'points_elsewhere' : 'txt_missing',
        ]);

        if ($domain->created_at !== null && $domain->created_at->copy()->addHours($windowHours)->isPast()) {
            $domain->forceFill(['status' => DomainStatus::Failed, 'failure_reason' => 'verification_window_expired']);
        }

        $domain->save();

        return $domain;
    }

    /**
     * Confirms the edge serves a valid certificate (verified → active).
     */
    public function checkTls(Domain $domain): Domain
    {
        if (! $domain->isCustom() || $domain->verified_at === null) {
            return $domain;
        }

        $expiry = $this->tls->certificateExpiry($domain->domain);
        $result = (array) ($domain->dns_check_result ?? []);

        if ($expiry !== null && $expiry->isFuture()) {
            $wasActive = $domain->status === DomainStatus::Active;

            $domain->forceFill([
                'status' => DomainStatus::Active,
                'tls_status' => 'issued',
                'tls_expires_at' => $expiry,
                'tls_checked_at' => now(),
                'failure_reason' => null,
                'dns_check_result' => array_merge($result, ['tls_attempts' => 0]),
            ])->save();

            if (! $wasActive) {
                $this->notifyTenant($domain, 'tenant.custom_domain_verified');
            }

            return $domain;
        }

        $attempts = (int) ($result['tls_attempts'] ?? 0) + 1;

        $domain->forceFill([
            'tls_checked_at' => now(),
            'dns_check_result' => array_merge($result, ['tls_attempts' => $attempts]),
            'tls_status' => $attempts >= self::TLS_ATTEMPTS ? 'failed' : 'pending',
            'failure_reason' => $attempts >= self::TLS_ATTEMPTS ? 'tls_not_served' : $domain->failure_reason,
        ])->save();

        return $domain;
    }

    /**
     * The daily re-check of active and misconfigured domains. A
     * misconfigured domain keeps identifying the tenant (rule on §7.5).
     */
    public function recheck(Domain $domain): Domain
    {
        if (! $domain->isCustom() || ! in_array($domain->status, [DomainStatus::Active, DomainStatus::Misconfigured], true)) {
            return $domain;
        }

        [$routingType, $observed] = $this->routing($domain->domain);
        $expiry = $this->tls->certificateExpiry($domain->domain);

        $problem = match (true) {
            $routingType === null => 'points_elsewhere',
            $expiry === null => 'tls_not_served',
            $expiry->lt(now()->addDays(self::CERTIFICATE_WARNING_DAYS)) => 'certificate_expiring',
            default => null,
        };

        $wasActive = $domain->status === DomainStatus::Active;

        $domain->forceFill([
            'last_dns_check_at' => now(),
            'dns_check_result' => array_merge((array) ($domain->dns_check_result ?? []), $observed),
            'routing_type' => $routingType ?? $domain->routing_type,
            'tls_expires_at' => $expiry ?? $domain->tls_expires_at,
            'tls_checked_at' => now(),
            'status' => $problem === null ? DomainStatus::Active : DomainStatus::Misconfigured,
            'failure_reason' => $problem,
        ])->save();

        if ($problem !== null && $wasActive) {
            $tenant = Tenant::query()->findOrFail($domain->tenant_id);
            $fallback = Domain::query()->where('tenant_id', $tenant->id)->where('type', 'subdomain')->value('domain');

            $this->notifications->dispatch('tenant.custom_domain_misconfigured', $tenant, [
                'owner_name' => $tenant->owner_name,
                'domain' => $domain->domain,
                'problem' => str_replace('_', ' ', $problem),
                'fallback_domain' => (string) $fallback,
            ]);
        }

        return $domain;
    }

    public function makePrimary(Domain $domain): Domain
    {
        if ($domain->isCustom() && $domain->status !== DomainStatus::Active) {
            throw ApiException::unprocessable('domain_not_active', 'Only an active domain with a working certificate can be primary.');
        }

        DB::connection('landlord')->transaction(static function () use ($domain): void {
            Domain::query()->where('tenant_id', $domain->tenant_id)->where('id', '!=', $domain->id)->update(['is_primary' => false]);
            $domain->forceFill(['is_primary' => true])->save();
        });

        return $domain;
    }

    public function removeDomain(Domain $domain): void
    {
        if (! $domain->isCustom()) {
            throw ApiException::unprocessable('domain_not_removable', 'The store subdomain cannot be removed.');
        }

        DB::connection('landlord')->transaction(static function () use ($domain): void {
            if ($domain->is_primary) {
                Domain::query()->where('tenant_id', $domain->tenant_id)->where('type', 'subdomain')->update(['is_primary' => true]);
            }

            $domain->delete();
        });
    }

    /**
     * Whether the edge may request a certificate for a host (rule 2).
     */
    public function allowsCertificate(string $host): bool
    {
        $domain = Domain::query()->where('domain', strtolower($host))->where('type', 'custom')->whereNotNull('verified_at')->first();

        if ($domain === null) {
            return false;
        }

        return Tenant::query()->whereKey($domain->tenant_id)->where('status', 'active')->exists();
    }

    public function isApex(string $host): bool
    {
        $labels = explode('.', $host);
        $count = count($labels);

        return $count === 2 || ($count === 3 && in_array($labels[1], self::SECOND_LEVEL, true) && strlen($labels[2]) === 2);
    }

    private function txtHost(Domain $domain): string
    {
        return $this->settings->get('custom_domain_verification_prefix', '_platform-verify').'.'.$domain->domain;
    }

    /**
     * A CNAME to the platform target, or A/AAAA records all within the
     * configured dedicated addresses.
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function routing(string $host): array
    {
        $target = strtolower((string) $this->settings->get('custom_domain_cname_target'));
        $ipv4 = (array) $this->settings->get('custom_domain_ipv4_addresses', []);
        $ipv6 = (array) $this->settings->get('custom_domain_ipv6_addresses', []);

        $cname = $this->dns->cname($host);
        $a = $this->dns->a($host);
        $aaaa = $this->dns->aaaa($host);
        $observed = ['cname' => $cname, 'a' => $a, 'aaaa' => $aaaa];

        if ($cname !== null && $target !== '' && $cname === $target) {
            return ['cname', $observed];
        }

        $addresses = array_merge($a, $aaaa);
        $allowed = array_merge($ipv4, $ipv6);

        if ($addresses !== [] && $allowed !== [] && array_diff($addresses, $allowed) === []) {
            return ['a_record', $observed];
        }

        return [null, $observed];
    }

    private function notifyTenant(Domain $domain, string $key): void
    {
        $tenant = Tenant::query()->find($domain->tenant_id);

        if ($tenant === null || $tenant->provisioned_at === null) {
            return;
        }

        $send = fn () => $this->notifications->dispatch($key, null, ['domain' => $domain->domain]);

        tenant()?->getTenantKey() === $tenant->getTenantKey() ? $send() : $tenant->run($send);
    }
}
