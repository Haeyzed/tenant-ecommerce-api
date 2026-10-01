<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use Database\Seeders\Landlord\NotificationTemplateSeeder;
use Database\Seeders\Landlord\PlatformAccessSeeder;

beforeEach(function (): void {
    $this->seed([PlatformAccessSeeder::class, NotificationTemplateSeeder::class]);
    $root = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $root->assignRole('super-admin');
    $this->auth = ['Authorization' => 'Bearer '.$root->createToken('t', ['platform'])->plainTextToken];
});

it('lists placeholders including the platform name and rejects unknown ones', function (): void {
    $this->landlordJson('GET', '/api/admin/notification-templates?search=provisioning_complete', [], $this->auth)
        ->assertOk()
        ->assertJsonPath('data.0.key', 'tenant.provisioning_complete')
        ->assertJsonFragment(['variables' => ['owner_name', 'tenant_name', 'admin_url', 'platform_name']]);

    // A typo would reach recipients as raw braces, so it is refused.
    $this->landlordJson('PATCH', '/api/admin/notification-templates/tenant.provisioning_complete', ['body' => 'Hi {{ownr_name}}'], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors(['body']);

    $this->landlordJson('PATCH', '/api/admin/notification-templates/tenant.provisioning_complete', [
        'subject' => '{{tenant_name}} on {{platform_name}}', 'body' => 'Hi {{ owner_name }}',
    ], $this->auth)
        ->assertOk()
        ->assertJsonPath('data.is_customized', true)
        ->assertJsonPath('data.preview.body', 'Hi [owner_name]');

    $this->landlordJson('POST', '/api/admin/notification-templates/tenant.provisioning_complete/reset', [], $this->auth)
        ->assertOk()->assertJsonPath('data.is_customized', false);
});

it('keeps mandatory templates active and on at least one channel', function (): void {
    $this->landlordJson('PATCH', '/api/admin/notification-templates/tenant.registration_verification', ['is_active' => false], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors(['is_active']);

    $this->landlordJson('PATCH', '/api/admin/notifications/matrix/tenant.registration_verification', ['channels' => ['email' => false]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors(['channels']);

    $this->landlordJson('PATCH', '/api/admin/notifications/matrix/tenant.provisioning_complete', ['channels' => ['sms' => true], 'audience' => ['tenant', 'platform_user']], $this->auth)
        ->assertOk()->assertJsonPath('data.channels.sms', true)->assertJsonPath('data.target_audience', ['tenant', 'platform_user']);

    $this->landlordJson('PATCH', '/api/admin/notifications/matrix/tenant.provisioning_complete', ['audience' => ['customer']], $this->auth)
        ->assertStatus(422);
});
