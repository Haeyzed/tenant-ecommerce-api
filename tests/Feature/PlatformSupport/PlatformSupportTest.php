<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\PlatformSupport\Events\PlatformSupportMessageSent;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\Users\Models\User;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    Event::fake([PlatformSupportMessageSent::class]);
    $this->seed(PlatformAccessSeeder::class);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);

    $this->support = PlatformUser::query()->create(['name' => 'Sam Support', 'email' => 'sam@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->support->assignRole('support-staff');
    $this->platformAuth = ['Authorization' => 'Bearer '.$this->support->createToken('t', ['platform'])->plainTextToken];

    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'basic');
    tenancy()->initialize($this->tenant);
    $this->clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->tenantAuth = ['Authorization' => 'Bearer '.$this->clerk->createToken('t', ['staff'])->plainTextToken];
});

it('lets any staff user open a conversation and alerts platform support', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/platform-support/conversations', [
        'subject' => 'Stuck on billing', 'category' => 'billing', 'body' => 'My card was charged twice.',
    ], $this->tenantAuth)->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.messages.0.body', 'My card was charged twice.')
        ->assertJsonMissingPath('data.assignee')
        ->json('data.id');

    Event::assertDispatched(PlatformSupportMessageSent::class, fn ($e): bool => $e->conversationId === $id);
    Notification::assertSentTo($this->support, TemplatedNotification::class, fn ($n): bool => $n->key === 'platform_support.message_received');
});

it('keeps internal notes away from the tenant and routes replies both ways', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/platform-support/conversations', ['subject' => 'Help', 'category' => 'technical', 'body' => 'Hi'], $this->tenantAuth)->json('data.id');

    $this->landlordJson('POST', "/api/admin/platform-support/conversations/{$id}/notes", ['body' => 'Probably a caching issue'], $this->platformAuth)->assertCreated();
    $this->landlordJson('POST', "/api/admin/platform-support/conversations/{$id}/messages", ['body' => 'Looking into it'], $this->platformAuth)->assertCreated();

    $this->landlordJson('GET', "/api/admin/platform-support/conversations/{$id}", [], $this->platformAuth)
        ->assertOk()->assertJsonCount(3, 'data.messages')->assertJsonPath('data.status', 'pending');

    // Ending tenancy for the landlord requests rolled back this tenant's test
    // transaction; the conversation itself is landlord data and remains.
    tenancy()->initialize($this->tenant);
    $viewer = User::query()->create(['name' => 'Viewer', 'email' => 'viewer@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->tenantJson('GET', "/api/admin/platform-support/conversations/{$id}", [], ['Authorization' => 'Bearer '.$viewer->createToken('t', ['staff'])->plainTextToken])
        ->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.1.body', 'Looking into it');

    Notification::assertSentTo($this->tenant, TemplatedNotification::class, fn ($n): bool => $n->key === 'platform_support.reply_received');
});

it('lets platform staff triage conversations', function (): void {
    $id = $this->tenantJson('POST', '/api/admin/platform-support/conversations', ['subject' => 'Down', 'category' => 'bug', 'body' => 'Store is down'], $this->tenantAuth)->json('data.id');

    $this->landlordJson('PATCH', "/api/admin/platform-support/conversations/{$id}", ['priority' => 'urgent', 'assigned_to' => $this->support->id, 'status' => 'resolved'], $this->platformAuth)
        ->assertOk()->assertJsonPath('data.priority', 'urgent')->assertJsonPath('data.assignee.name', 'Sam Support')->assertJsonPath('data.status', 'resolved');

    $this->landlordJson('GET', '/api/admin/platform-support/conversations?assignee='.$this->support->id, [], $this->platformAuth)
        ->assertOk()->assertJsonPath('data.0.id', $id);
});

it('never shows one tenant another tenant\'s conversations', function (): void {
    $foreign = PlatformSupportConversation::query()->create([
        'tenant_id' => $this->createTenantRow('b'), 'raised_by_user_id' => 1, 'raised_by_name' => 'X', 'raised_by_email' => 'x@b.test',
        'subject' => 'Secret', 'category' => 'other', 'status' => 'open', 'priority' => 'normal',
    ]);

    $this->tenantJson('GET', "/api/admin/platform-support/conversations/{$foreign->id}", [], $this->tenantAuth)->assertNotFound();
    $this->tenantJson('POST', "/api/admin/platform-support/conversations/{$foreign->id}/messages", ['body' => 'x'], $this->tenantAuth)->assertNotFound();
    $this->tenantJson('GET', '/api/admin/platform-support/conversations', [], $this->tenantAuth)->assertOk()->assertJsonCount(0, 'data');
});

it('rejects unsafe attachments', function (): void {
    $this->tenantJson('POST', '/api/admin/platform-support/conversations', [
        'subject' => 'File', 'category' => 'other', 'body' => 'See attached',
        'attachments' => [UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload')],
    ], $this->tenantAuth)->assertStatus(422);
});
