<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Services\TenantDomainService;
use App\Modules\Users\Models\User;
use App\Shared\Support\FrontendUrl;

beforeEach(function (): void {
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    tenancy()->initialize($this->tenant);
});

it('builds store admin and seller portal links on the admin origin', function (): void {
    expect(FrontendUrl::tenantAdmin($this->tenant, '/billing/callback', ['reference' => 'R1']))
        ->toBe('https://tenant-a.admin.platform.test/billing/callback?reference=R1')
        ->and(FrontendUrl::sellerPortal($this->tenant, '/reset-password', ['token' => 't']))
        ->toBe('https://tenant-a.admin.platform.test/seller/reset-password?token=t')
        // The storefront stays on the store's own domain.
        ->and(FrontendUrl::storefront($this->tenant, '/verify-email'))->toBe('https://tenant-a.platform.test/verify-email');
});

it('exposes the store identity and every storefront module flag in the public config and staff profile', function (): void {
    $this->tenantJson('GET', '/api/storefront/config')->assertOk()
        ->assertJsonPath('data.tenant', ['id' => $this->tenant->getTenantKey(), 'slug' => 'tenant-a', 'primary_domain' => 'tenant-a.platform.test'])
        ->assertJsonPath('data.modules.support', false)
        ->assertJsonPath('data.modules.marketplace', false)
        ->assertJsonPath('data.modules.hr_recruitment', false)
        ->assertJsonPath('data.modules.sales_quotations', false)
        ->assertJsonPath('data.modules.product_subscriptions', false)
        ->assertJsonPath('data.modules.repair', false);

    tenancy()->initialize($this->tenant);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken])->assertOk()
        ->assertJsonPath('data.tenant.slug', 'tenant-a')
        ->assertJsonPath('data.tenant.primary_domain', 'tenant-a.platform.test');
});

it('refreshes the primary domain in the storefront config when it changes', function (): void {
    $this->tenantJson('GET', '/api/storefront/config')->assertJsonPath('data.tenant.primary_domain', 'tenant-a.platform.test');

    tenancy()->initialize($this->tenant);
    $custom = Domain::query()->create(['tenant_id' => $this->tenant->getTenantKey(), 'domain' => 'shop.example.test', 'type' => 'custom', 'is_primary' => false,
        'status' => 'active', 'verified_at' => now()]);
    app(TenantDomainService::class)->makePrimary($custom);

    $this->tenantJson('GET', '/api/storefront/config')->assertJsonPath('data.tenant.primary_domain', 'shop.example.test');
});
