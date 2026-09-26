<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Notifications\Services\NotificationMatrixService;
use App\Modules\Notifications\Services\NotificationPreferenceService;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->dispatcher = app(NotificationDispatchService::class);
});

function staffOwner(string $email = 'owner@a.test'): User
{
    $user = User::query()->create(['name' => 'Owner', 'email' => $email, 'password' => 'secret123', 'is_active' => true]);
    $user->assignRole('owner');

    return $user;
}

it('seeds every tenant catalog key once with its channel matrix', function (): void {
    tenancy()->initialize($this->createTenant('a'));

    $keys = array_keys(app(NotificationCatalog::class)->definitions(NotificationScope::Tenant));

    expect(NotificationTemplate::forScope(NotificationScope::Tenant)->count())->toBe(count($keys))
        ->and(app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant))->toBe(0);

    $template = NotificationTemplate::forScope(NotificationScope::Tenant)->with('channels')->where('key', 'order.confirmed')->firstOrFail();
    expect($template->is_mandatory)->toBeTrue()
        ->and($template->channels)->toHaveCount(5)
        ->and($template->channelMatrix()['sms'])->toBeTrue()
        ->and($template->channelMatrix()['whatsapp'])->toBeFalse();
});

it('seeds landlord templates without touching customised rows', function (): void {
    $service = app(NotificationTemplateService::class);
    $service->seedDefaults(NotificationScope::Landlord);
    $service->updateTemplate('subscription.renewed', ['body' => 'Custom body'], NotificationScope::Landlord);

    $service->seedDefaults(NotificationScope::Landlord);

    $row = NotificationTemplate::forScope(NotificationScope::Landlord)->where('key', 'subscription.renewed')->firstOrFail();
    expect($row->body)->toBe('Custom body')->and($row->is_customized)->toBeTrue();

    $service->resetToDefault('subscription.renewed', NotificationScope::Landlord);
    expect($row->refresh()->is_customized)->toBeFalse();
});

it('sends to the owner and admin staff on enabled channels', function (): void {
    tenancy()->initialize($this->createTenant('a'));
    $owner = staffOwner();
    $plain = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'secret123', 'is_active' => true]);

    $this->dispatcher->dispatch('module.state_changed', null, ['module_name' => 'POS', 'state' => 'enabled', 'detail' => '']);

    Notification::assertSentTo($owner, TemplatedNotification::class, function (TemplatedNotification $n) {
        return $n->subject === 'POS is now enabled'
            && $n->channels === ['database', 'email']
            && $n->scope === NotificationScope::Tenant;
    });
    Notification::assertNotSentTo($plain, TemplatedNotification::class);
});

it('honours the kill switch, the matrix and preferences, except for mandatory templates', function (): void {
    tenancy()->initialize($this->createTenant('a'));
    $owner = staffOwner();

    app(NotificationPreferenceService::class)->setPreference($owner, 'module.state_changed', 'email', false);
    $this->dispatcher->dispatch('module.state_changed', $owner, ['module_name' => 'POS', 'state' => 'enabled', 'detail' => '']);
    Notification::assertSentTo($owner, TemplatedNotification::class, fn (TemplatedNotification $n) => $n->channels === ['database']);

    app(NotificationMatrixService::class)->updateChannels('module.state_changed', ['database' => false]);
    Notification::fake();
    $this->dispatcher->dispatch('module.state_changed', $owner, ['module_name' => 'POS', 'state' => 'enabled', 'detail' => '']);
    Notification::assertNothingSent();

    app(TenantSettingsService::class)->set('notifications_enabled', false);
    $this->dispatcher->dispatch('staff.password_reset', $owner, ['name' => 'Owner', 'reset_url' => 'https://x.test/r', 'expires_in_minutes' => 60]);
    Notification::assertSentTo($owner, TemplatedNotification::class, fn (TemplatedNotification $n) => $n->key === 'staff.password_reset');

    expect(fn () => app(NotificationPreferenceService::class)->setPreference($owner, 'staff.password_reset', 'email', false))
        ->toThrow(ValidationException::class);
});

it('drops explicit recipients outside the template audience', function (): void {
    tenancy()->initialize($this->createTenant('a'));
    $owner = staffOwner();

    app(NotificationMatrixService::class)->updateAudience('module.state_changed', ['customer']);
    $this->dispatcher->dispatch('module.state_changed', $owner, ['module_name' => 'POS', 'state' => 'enabled', 'detail' => '']);

    Notification::assertNothingSent();
});

it('dispatches landlord keys from the landlord templates even inside a tenant context', function (): void {
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    $tenant = $this->createTenant('a');
    tenancy()->initialize($tenant);

    $this->dispatcher->dispatch('subscription.renewed', $tenant, [
        'owner_name' => 'Owner A', 'plan_name' => 'Basic', 'renews_at' => '2026-11-01', 'amount' => 'USD 20.00',
    ]);

    Notification::assertSentTo($tenant, TemplatedNotification::class, fn (TemplatedNotification $n) => $n->scope === NotificationScope::Landlord
        && $n->channels === ['email']
        && str_contains($n->body, 'Owner A'));
});

it('keeps unknown placeholders and logs only their names', function (): void {
    Log::spy();

    $rendered = app(NotificationTemplateService::class)->renderContent('x', 'Hi {{name}}', 'Code {{code}} for {{name}}', ['name' => 'Ada']);

    expect($rendered)->toBe(['subject' => 'Hi Ada', 'body' => 'Code {{code}} for Ada']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['placeholders'] === ['code']);
});

it('rejects deactivating a mandatory template or removing its last channel', function (): void {
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);

    expect(fn () => app(NotificationTemplateService::class)->updateTemplate('platform_user.password_reset', ['is_active' => false], NotificationScope::Landlord))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(NotificationMatrixService::class)->updateChannels('platform_user.password_reset', ['email' => false], NotificationScope::Landlord))
        ->toThrow(ValidationException::class);
});

it('delivers platform user notifications to the in-app inbox and email', function (): void {
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    $admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'secret123']);

    $this->dispatcher->dispatch('platform.onboarding_paused', $admin, ['settings' => 'tenant_registration_enabled', 'reason' => 'Fraud wave']);

    Notification::assertSentTo($admin, TemplatedNotification::class, fn (TemplatedNotification $n) => $n->channels === ['database', 'email']);
});
