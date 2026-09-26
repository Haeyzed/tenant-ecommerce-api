<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Tenancy\Jobs\ExportTenant;
use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Users\Models\User;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(PlatformAccessSeeder::class);
    $admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $admin->assignRole('super-admin');
    $this->auth = ['Authorization' => 'Bearer '.$admin->createToken('t', ['platform'])->plainTextToken];
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
});

it('lists and shows tenants with subscription, modules and usage', function (): void {
    $this->landlordJson('GET', '/api/admin/tenants?search=Tenant', [], $this->auth)->assertOk()->assertJsonPath('data.0.id', 'test-tenant-a');

    $this->landlordJson('GET', '/api/admin/tenants/test-tenant-a', [], $this->auth)
        ->assertOk()
        ->assertJsonPath('data.subscription.plan.slug', 'basic')
        ->assertJsonPath('data.usage.max_users.limit', 2)
        ->assertJsonMissingPath('data.tenant.data');
});

it('suspends and reactivates, blocking the store in between', function (): void {
    $this->landlordJson('POST', '/api/admin/tenants/test-tenant-a/suspend', ['reason' => 'Abuse report'], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    $this->tenantJson('GET', '/api/module-notices')->assertForbidden()->assertJsonPath('meta.error_code', 'tenant_suspended');

    $this->landlordJson('POST', '/api/admin/tenants/test-tenant-a/reactivate', [], $this->auth)->assertOk()->assertJsonPath('data.status', 'active');
    $this->landlordJson('POST', '/api/admin/tenants/test-tenant-a/reactivate', [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');
});

it('closes with a retention window, revoking tokens, and restores', function (): void {
    tenancy()->initialize($this->tenant);
    $user = User::query()->create(['name' => 'U', 'email' => 'u@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $user->createToken('t', ['staff']);
    tenancy()->end();

    $this->landlordJson('POST', '/api/admin/tenants/test-tenant-a/close', ['reason' => 'Owner request'], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'closed');

    $tenant = $this->tenant->refresh();
    expect($tenant->purge_after->isSameDay(now()->addDays(90)))->toBeTrue();

    $this->tenantJson('GET', '/api/module-notices')->assertStatus(410);

    $this->landlordJson('POST', '/api/admin/tenants/test-tenant-a/restore', [], $this->auth)->assertOk()->assertJsonPath('data.status', 'active');
});

it('queues a full export for the requesting platform user', function (): void {
    Bus::fake([ExportTenant::class]);

    $this->landlordJson('POST', '/api/admin/tenants/test-tenant-a/export', [], $this->auth)->assertStatus(202);

    Bus::assertDispatched(ExportTenant::class, fn (ExportTenant $job): bool => $job->tenantId === 'test-tenant-a');
});

it('registers database servers without ever returning the password', function (): void {
    $this->landlordJson('POST', '/api/admin/database-servers', [
        'name' => 'db-eu-1', 'host' => '10.0.0.5', 'username' => 'tenancy', 'password' => 'secret-pass', 'max_tenants' => 500,
    ], $this->auth)->assertCreated()->assertJsonMissingPath('data.password')->assertJsonPath('data.utilisation', 0);

    $server = DatabaseServer::query()->firstOrFail();
    expect($server->password)->toBe('secret-pass');

    $this->landlordJson('PATCH', "/api/admin/database-servers/{$server->id}", ['is_accepting_tenants' => false], $this->auth)
        ->assertOk()->assertJsonPath('data.is_accepting_tenants', false);
});

it('never lets an unauthorised caller reach the edge endpoint', function (): void {
    config(['app.edge_shared_secret' => 'edge-secret', 'app.edge_allowed_ips' => '127.0.0.1']);

    $this->landlordJson('GET', '/api/internal/domains/allowed?domain=shop.example.com')->assertNotFound();
    $this->landlordJson('GET', '/api/internal/domains/allowed?domain=shop.example.com', [], ['X-Edge-Secret' => 'wrong'])->assertNotFound();
    $this->landlordJson('GET', '/api/internal/domains/allowed?domain=shop.example.com', [], ['X-Edge-Secret' => 'edge-secret'])->assertNotFound();

    Domain::query()->create([
        'domain' => 'shop.example.com', 'tenant_id' => 'test-tenant-a', 'type' => 'custom', 'status' => 'verified', 'verified_at' => now(),
    ]);

    $this->landlordJson('GET', '/api/internal/domains/allowed?domain=shop.example.com', [], ['X-Edge-Secret' => 'edge-secret'])->assertOk();
});
