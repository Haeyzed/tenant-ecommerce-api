<?php

declare(strict_types=1);

use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\DomainStatus;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Services\TenantDomainService;
use App\Modules\Tenancy\Support\DnsResolver;
use App\Modules\Tenancy\Support\TlsProbe;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * A DNS stand-in whose records the test sets.
 */
final class FakeDns extends DnsResolver
{
    /** @var array<string, array<int, list<array<string, mixed>>>> */
    public array $records = [];

    protected function lookup(string $host, int $type): array
    {
        return $this->records[$host][$type] ?? [];
    }
}

final class FakeTls extends TlsProbe
{
    public ?Carbon $expiry = null;

    public function certificateExpiry(string $host): ?Carbon
    {
        return $this->expiry;
    }
}

beforeEach(function (): void {
    Notification::fake();
    $settings = app(PlatformSettingsService::class);
    $settings->set('custom_domains_enabled', true);
    $settings->set('custom_domain_cname_target', 'edge.platform.test');
    $settings->set('custom_domain_ipv4_addresses', ['203.0.113.10']);

    $this->dns = new FakeDns;
    $this->tls = new FakeTls;
    $this->app->instance(DnsResolver::class, $this->dns);
    $this->app->instance(TlsProbe::class, $this->tls);

    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'standard');

    tenancy()->initialize($this->tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];
});

it('adds a custom domain with DNS instructions and refuses platform hosts', function (): void {
    // Validated before the plan limit (one custom domain) is used up.
    $this->tenantJson('POST', '/api/admin/domains', ['domain' => 'evil.platform.test'], $this->auth)->assertStatus(422);

    $this->tenantJson('POST', '/api/admin/domains', ['domain' => 'Shop.Example.com.'], $this->auth)
        ->assertCreated()
        ->assertJsonPath('data.domain', 'shop.example.com')
        ->assertJsonPath('data.status', 'pending_verification')
        ->assertJsonPath('data.dns_instructions.0.host', '_platform-verify.shop.example.com')
        ->assertJsonPath('data.dns_instructions.1', ['type' => 'CNAME', 'host' => 'shop.example.com', 'value' => 'edge.platform.test']);
});

it('enforces the plan limit on custom domains', function (): void {
    $this->tenantJson('POST', '/api/admin/domains', ['domain' => 'one.example.com'], $this->auth)->assertCreated();
    $this->tenantJson('POST', '/api/admin/domains', ['domain' => 'two.example.com'], $this->auth)
        ->assertForbidden()->assertJsonPath('meta.error_code', 'limit_reached');
});

it('verifies ownership and routing, then activates once TLS is served', function (): void {
    $service = app(TenantDomainService::class);
    $domain = $service->addCustomDomain($this->tenant, 'example.com');

    $service->verify($domain);
    expect($domain->refresh()->status)->toBe(DomainStatus::PendingVerification)->and($domain->failure_reason)->toBe('txt_missing');

    $this->dns->records['_platform-verify.example.com'][DNS_TXT] = [['txt' => $domain->verification_token]];
    $this->dns->records['example.com'][DNS_A] = [['ip' => '203.0.113.10']];

    $service->verify($domain);
    expect($domain->refresh()->status)->toBe(DomainStatus::Verified)->and($domain->routing_type)->toBe('a_record');

    $this->tls->expiry = now()->addMonths(3);
    $service->checkTls($domain);

    expect($domain->refresh()->status)->toBe(DomainStatus::Active)->and($domain->identifiesTenant())->toBeTrue();

    $service->makePrimary($domain);
    expect(Domain::query()->where('tenant_id', 'test-tenant-a')->where('is_primary', true)->value('domain'))->toBe('example.com');
});

it('marks a domain misconfigured but keeps it identifying the tenant', function (): void {
    $domain = Domain::query()->create([
        'domain' => 'shop.example.com', 'tenant_id' => 'test-tenant-a', 'type' => 'custom', 'status' => 'active',
        'verified_at' => now(), 'routing_type' => 'cname', 'tls_status' => 'issued',
    ]);

    app(TenantDomainService::class)->recheck($domain);

    expect($domain->refresh()->status)->toBe(DomainStatus::Misconfigured)
        ->and($domain->failure_reason)->toBe('points_elsewhere')
        ->and($domain->identifiesTenant())->toBeTrue();
});

it('fails verification after the window and never removes the subdomain', function (): void {
    $service = app(TenantDomainService::class);
    $domain = $service->addCustomDomain($this->tenant, 'late.example.com');

    $this->travel(73)->hours();
    $service->verify($domain);
    expect($domain->refresh()->status)->toBe(DomainStatus::Failed)->and($domain->failure_reason)->toBe('verification_window_expired');

    $subdomain = Domain::query()->where('tenant_id', 'test-tenant-a')->where('type', 'subdomain')->firstOrFail();
    $this->tenantJson('DELETE', "/api/admin/domains/{$subdomain->id}", [], $this->auth)->assertStatus(422);
});

it('hides other tenants\' domains', function (): void {
    // A landlord row of another tenant; creating a real second tenant here
    // would roll back this tenant's test transaction.
    $other = Domain::query()->create(['domain' => 'other.example.com', 'tenant_id' => 'test-tenant-a', 'type' => 'custom', 'status' => 'pending_verification']);
    $other->forceFill(['tenant_id' => $this->createTenantRow('b')])->save();

    $this->tenantJson('GET', "/api/admin/domains/{$other->id}", [], $this->auth)->assertNotFound();
});
