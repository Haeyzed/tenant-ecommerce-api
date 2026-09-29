<?php

declare(strict_types=1);

use App\Modules\Messaging\Channels\TenantStaffInboxChannel;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->tenant = $this->createTenant('a');
    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->clerk->assignRole('staff');
    // Tenancy stays initialised: the test transaction's rows are invisible
    // to a fresh tenant connection, which run() from landlord context opens.
});

it('routes landlord notifications to the tenant inbox only while its staff can sign in', function (): void {
    Notification::fake();
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    $send = fn () => app(NotificationDispatchService::class)->dispatch('subscription.renewed', $this->tenant,
        ['owner_name' => 'Ada', 'plan_name' => 'Basic', 'renews_at' => '2026-11-01', 'amount' => 'NGN 5,000.00']);

    $send();
    Notification::assertSentTo($this->tenant, TemplatedNotification::class,
        fn (TemplatedNotification $n): bool => in_array('database', $n->channels, true)
            && in_array(TenantStaffInboxChannel::class, $n->via($this->tenant), true)
            && ! in_array('database', $n->via($this->tenant), true));

    $this->tenant->forceFill(['status' => TenantStatus::Closed])->save();
    Notification::fake();
    $send();
    Notification::assertSentTo($this->tenant, TemplatedNotification::class, fn (TemplatedNotification $n): bool => ! in_array('database', $n->channels, true));
});

it('writes one inbox row per active owner or admin in the tenant database', function (): void {
    $notification = new TemplatedNotification('subscription.payment_failed', NotificationScope::Landlord, 'Payment failed', 'Please update your card.', ['database']);

    app(TenantStaffInboxChannel::class)->send($this->tenant, $notification);

    expect(tenant()?->getTenantKey())->toBe($this->tenant->getTenantKey())
        ->and($this->clerk->notifications()->count())->toBe(0)
        ->and($this->owner->notifications()->count())->toBe(1);

    $auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    $this->tenantJson('GET', '/api/admin/notifications', [], $auth)->assertOk()
        ->assertJsonPath('data.0.key', 'subscription.payment_failed')
        ->assertJsonPath('data.0.subject', 'Payment failed')
        ->assertJsonPath('data.0.source', 'platform')
        ->assertJsonPath('meta.unread_count', 1);
});
