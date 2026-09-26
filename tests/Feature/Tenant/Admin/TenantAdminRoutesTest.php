<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Users\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'standard');

    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    $this->clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->clerkAuth = ['Authorization' => 'Bearer '.$this->clerk->createToken('t', ['staff'])->plainTextToken];
});

it('lists modules for any staff user but lets only permitted users enable them', function (): void {
    $this->tenantJson('GET', '/api/admin/modules', [], $this->clerkAuth)->assertOk()->assertJsonFragment(['key' => 'pos', 'state' => 'available']);
    $this->tenantJson('POST', '/api/admin/modules/pos/enable', [], $this->clerkAuth)->assertForbidden();

    $this->tenantJson('POST', '/api/admin/modules/pos/enable', [], $this->auth)->assertOk()->assertJsonPath('data.state', 'enabled');
    $this->tenantJson('POST', '/api/admin/modules/hr/enable', [], $this->auth)
        ->assertForbidden()->assertJsonPath('meta.error_code', 'feature_unavailable')->assertJsonPath('meta.details.entitled_by_plans', ['premium']);
});

it('updates tenant settings, refusing own-route keys and hiding the mail password', function (): void {
    $this->tenantJson('PATCH', '/api/admin/settings', ['values' => ['store_name' => 'Shop A', 'return_window_days' => 14]], $this->auth)
        ->assertOk()->assertJsonPath('data.store_name', 'Shop A');

    $this->tenantJson('PATCH', '/api/admin/settings', ['values' => ['payment_mode' => 'live']], $this->auth)->assertStatus(422);

    $this->tenantJson('PATCH', '/api/admin/settings', ['values' => [
        'email_provider' => 'custom',
        'custom_mail_settings' => ['mail_driver' => 'smtp', 'host' => 'smtp.a.test', 'port' => 587, 'username' => 'u', 'password' => 'p@ss', 'from_address' => 'shop@a.test'],
    ]], $this->auth)->assertOk()
        ->assertJsonPath('data.custom_mail_settings.has_password', true)
        ->assertJsonMissingPath('data.custom_mail_settings.password');
});

it('serves the public storefront config', function (): void {
    $this->tenantJson('PATCH', '/api/admin/storefront-settings', ['values' => ['color_primary' => '#112233']], $this->auth)->assertOk();

    $this->tenantJson('GET', '/api/storefront/config')
        ->assertOk()
        ->assertJsonPath('data.storefront.color_primary', '#112233')
        ->assertJsonPath('data.business.store_name', 'Tenant A')
        ->assertJsonPath('data.checkout.payment_mode', 'test')
        ->assertJsonPath('data.modules.gift_cards', false);
});

it('edits notification templates with a rendered preview', function (): void {
    $this->tenantJson('GET', '/api/admin/notification-templates?search=order.confirmed', [], $this->auth)
        ->assertOk()->assertJsonPath('data.0.key', 'order.confirmed')->assertJsonPath('data.0.is_mandatory', true);

    $this->tenantJson('PATCH', '/api/admin/notification-templates/order.confirmed', ['body' => 'Thanks {{customer_name}}!'], $this->auth)
        ->assertOk()->assertJsonPath('data.preview.body', 'Thanks [customer_name]!')->assertJsonPath('data.is_customized', true);

    $this->tenantJson('PATCH', '/api/admin/notifications/matrix/order.confirmed', ['channels' => ['sms' => false]], $this->auth)
        ->assertOk()->assertJsonPath('data.channels.sms', false);
});

it('gives every staff user their own inbox and preferences', function (): void {
    // Written through the real database channel: notifications are faked
    // for this file, and queued ones wait for a commit the test never makes.
    $notification = new TemplatedNotification('module.state_changed', NotificationScope::Tenant, 'POS is enabled', 'Body', ['database']);
    $notification->id = (string) Str::uuid();
    (new DatabaseChannel)->send($this->owner, $notification);

    $id = $this->tenantJson('GET', '/api/admin/notifications', [], $this->auth)
        ->assertOk()->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.0.subject', 'POS is enabled')->json('data.0.id');

    $this->tenantJson('GET', '/api/admin/notifications', [], $this->clerkAuth)->assertOk()->assertJsonCount(0, 'data');
    $this->tenantJson('POST', "/api/admin/notifications/{$id}/read", [], $this->clerkAuth)->assertNotFound();
    $this->tenantJson('POST', "/api/admin/notifications/{$id}/read", [], $this->auth)->assertOk();

    $this->tenantJson('PATCH', '/api/admin/notification-preferences', ['template_key' => null, 'channel' => 'email', 'enabled' => false], $this->clerkAuth)
        ->assertOk()->assertJsonPath('data.0.enabled', false);
});

it('masks SMS gateway credentials', function (): void {
    $this->tenantJson('POST', '/api/admin/sms-gateway-settings', ['provider' => 'termii', 'credentials' => ['api_key' => 'TL-secret-key-1234', 'sender_id' => 'SHOPA']], $this->auth)
        ->assertOk()->assertJsonPath('data.0.credentials.api_key', '**************1234');
});

it('keeps WhatsApp settings behind the whatsapp module', function (): void {
    $this->tenantJson('GET', '/api/admin/whatsapp-settings', [], $this->auth)->assertForbidden()->assertJsonPath('meta.error_code', 'module_disabled');

    $this->tenantJson('POST', '/api/admin/modules/whatsapp/enable', [], $this->auth)->assertOk();
    $this->tenantJson('GET', '/api/admin/whatsapp-settings', [], $this->auth)->assertOk()->assertJsonPath('data.uses_platform_number', true);
});
