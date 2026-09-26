<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(PlatformAccessSeeder::class);
    $this->root = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->root->assignRole('super-admin');
    $this->auth = ['Authorization' => 'Bearer '.$this->root->createToken('t', ['platform'])->plainTextToken];
});

it('invites a platform user with a set-password link and roles', function (): void {
    $id = $this->landlordJson('POST', '/api/admin/platform-users', ['name' => 'Bola', 'email' => 'Bola@Platform.test', 'roles' => ['billing-admin']], $this->auth)
        ->assertCreated()->assertJsonPath('data.email', 'bola@platform.test')->assertJsonPath('data.has_password', false)
        ->assertJsonPath('data.roles', ['billing-admin'])->json('data.id');

    Notification::assertSentTo(PlatformUser::query()->findOrFail($id), TemplatedNotification::class, fn ($n): bool => $n->key === 'platform_user.invited'
        && str_contains($n->body, '/reset-password?token='));
});

it('never removes the last active super-admin', function (): void {
    $this->landlordJson('DELETE', "/api/admin/platform-users/{$this->root->id}/roles/super-admin", [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'last_super_admin');
    $this->landlordJson('POST', "/api/admin/platform-users/{$this->root->id}/deactivate", [], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'cannot_deactivate_self');
});

it('deactivates a user and revokes their tokens', function (): void {
    $user = PlatformUser::query()->create(['name' => 'Temp', 'email' => 'temp@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $user->assignRole('support-staff');
    $token = $user->createToken('t', ['platform'])->plainTextToken;

    $this->landlordJson('POST', "/api/admin/platform-users/{$user->id}/deactivate", [], $this->auth)->assertOk()->assertJsonPath('data.is_active', false);
    $this->landlordJson('GET', '/api/admin/auth/me', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('keeps platform user management with super-admins only', function (): void {
    $billing = PlatformUser::query()->create(['name' => 'Bill', 'email' => 'bill@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $billing->assignRole('billing-admin');

    $this->landlordJson('GET', '/api/admin/platform-users', [], ['Authorization' => 'Bearer '.$billing->createToken('t', ['platform'])->plainTextToken])
        ->assertForbidden();
});
