<?php

declare(strict_types=1);

use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'standard');

    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    // Tenancy stays initialised: ending it would purge the connection and
    // roll back this test's tenant transaction. Requests to the same tenant
    // host reuse the context.
});

function staffLogin(string $email = 'owner@a.test', string $password = 'Secret123', string $key = 'a'): TestResponse
{
    return test()->tenantJson('POST', '/api/admin/auth/login', ['email' => $email, 'password' => $password], [], $key);
}

it('logs staff in on the tenant domain and returns their profile', function (): void {
    $token = staffLogin()->assertOk()->json('data.token');

    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.is_owner', true)
        ->assertJsonPath('data.roles', ['owner'])
        ->assertJsonPath('data.modules.pos', 'available')
        ->assertJsonPath('data.modules.expenses', 'enabled')
        ->assertJsonPath('data.tenant_status', 'active')
        ->assertJsonPath('data.subscription_status', 'active')
        ->assertJsonPath('data.payment_mode', 'test');
});

it('does not accept a tenant token on another tenant', function (): void {
    $token = staffLogin()->json('data.token');

    $this->subscribe($this->createTenant('b'), 'standard');
    app('auth')->forgetGuards();

    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$token], 'b')->assertUnauthorized();
});

it('does not accept a staff token on the landlord domain', function (): void {
    $token = staffLogin()->json('data.token');

    $this->landlordJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('refuses deactivated staff', function (): void {
    $this->owner->forceFill(['is_active' => false])->save();

    staffLogin()->assertForbidden()->assertJsonPath('meta.error_code', 'account_disabled');
});

it('refreshes the token, revoking the old one', function (): void {
    $old = staffLogin()->json('data.token');

    $new = $this->tenantJson('POST', '/api/admin/auth/refresh', [], ['Authorization' => 'Bearer '.$old])->assertOk()->json('data.token');
    app('auth')->forgetGuards();

    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$old])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$new])->assertOk();
});

it('changes the password and revokes the other tokens', function (): void {
    $first = staffLogin()->json('data.token');
    $second = staffLogin()->json('data.token');

    $this->tenantJson('PATCH', '/api/admin/auth/password', [
        'current_password' => 'Secret123',
        'password' => 'NewSecret456',
        'password_confirmation' => 'NewSecret456',
    ], ['Authorization' => 'Bearer '.$second])->assertOk();
    app('auth')->forgetGuards();

    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$first])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->tenantJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$second])->assertOk();
});

it('sends the staff reset link to the tenant admin frontend', function (): void {
    $this->tenantJson('POST', '/api/admin/auth/password/forgot', ['email' => 'owner@a.test'])->assertStatus(202);

    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'staff.password_reset'
        && str_contains($n->body, 'https://tenant-a.platform.test/admin/reset-password?token='));
});

it('blocks tenants that are not active', function (TenantStatus $status, int $code, string $error): void {
    $this->tenant->forceFill(['status' => $status])->save();

    staffLogin()->assertStatus($code)->assertJsonPath('meta.error_code', $error);
})->with([
    [TenantStatus::Suspended, 403, 'tenant_suspended'],
    [TenantStatus::Provisioning, 503, 'tenant_provisioning'],
    [TenantStatus::AwaitingPayment, 402, 'subscription_payment_required'],
    [TenantStatus::Closed, 410, 'tenant_closed'],
]);

it('applies a suspension on the next request even after the host was cached', function (): void {
    staffLogin()->assertOk();

    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    staffLogin()->assertForbidden()->assertJsonPath('meta.error_code', 'tenant_suspended');
});

it('returns 404 for an unknown host', function (): void {
    $this->json('POST', 'http://unknown.platform.test/api/admin/auth/login', ['email' => 'x@y.test', 'password' => 'x'])
        ->assertNotFound();
});
