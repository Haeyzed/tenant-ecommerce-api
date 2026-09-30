<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Support\Events\SupportConversationStatusChanged;
use App\Modules\Support\Events\SupportMessageSent;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Support\GuestSupportActor;
use App\Modules\Users\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    Event::fake([SupportMessageSent::class, SupportConversationStatusChanged::class]);
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('store_name', 'Ada Stores');
    $this->owner = User::query()->create(['name' => 'Tola Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->cashier = User::query()->create(['name' => 'Cashier', 'email' => 'cashier@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->cashier->assignRole('staff');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    $this->cashierAuth = ['Authorization' => 'Bearer '.$this->cashier->createToken('t', ['staff'])->plainTextToken];
    $this->ada = Customer::query()->create(['name' => 'Ada', 'email' => 'ada@shop.test', 'password' => 'Secret123']);
    $this->adaAuth = ['Authorization' => 'Bearer '.$this->ada->createToken('t', ['customer'])->plainTextToken];
    $bola = Customer::query()->create(['name' => 'Bola', 'email' => 'bola@shop.test', 'password' => 'Secret123']);
    $this->bolaAuth = ['Authorization' => 'Bearer '.$bola->createToken('t', ['customer'])->plainTextToken];

    $this->tenantJson('POST', '/api/admin/modules/support/enable', [], $this->staff)->assertOk();
});

/**
 * Runs the registered channel callback for a support channel.
 */
function supportChannelAllows(string $suffix, object $user, mixed ...$parameters): bool
{
    $callback = app(BroadcastManager::class)->driver()->getChannels()->get('tenant.{tenantId}.'.$suffix);

    return (bool) $callback($user, test()->tenant->id, ...$parameters);
}

it('lets a guest chat without an account, takes the chat along on sign-in, and keeps internal notes and other people out', function (): void {
    $chat = $this->tenantJson('POST', '/api/support/conversations', ['channel' => 'chat', 'body' => 'Do you deliver to Abuja?'])->assertCreated()
        ->assertJsonPath('data.status', 'open')->assertJsonPath('data.messages.0.sender_type', 'guest')->json('data');
    $guest = ['X-Guest-Token' => $chat['guest_token']];
    expect(Str::isUuid($chat['guest_token']))->toBeTrue();
    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'support.message_received' && str_contains($n->body, 'Abuja'));
    Notification::assertNotSentTo($this->cashier, TemplatedNotification::class);
    Event::assertDispatched(SupportMessageSent::class, fn (SupportMessageSent $e): bool => ! $e->internal && count($e->broadcastOn()) === 2
        && $e->broadcastOn()[0]->name === 'private-tenant.'.$this->tenant->id.'.support-conversation.'.$chat['id']);

    $this->tenantJson('GET', '/api/support/conversations', [], $guest)->assertOk()->assertJsonCount(1, 'data');
    $this->tenantJson('GET', "/api/support/conversations/{$chat['id']}", [], ['X-Guest-Token' => (string) Str::uuid()])->assertNotFound();
    $this->tenantJson('GET', "/api/support/conversations/{$chat['id']}", [], $this->bolaAuth)->assertNotFound();

    // The inbox: unassigned with one unread; only agents can be assigned.
    // Resources and the inbox name their Echo channels (BG-05), matching the events.
    expect($chat['broadcast_channel'])->toBe('tenant.'.$this->tenant->id.'.support-conversation.'.$chat['id']);
    $this->tenantJson('GET', '/api/admin/support/conversations?assigned_to=unassigned', [], $this->staff)->assertOk()
        ->assertJsonPath('data.0.unread_count', 1)->assertJsonPath('data.0.guest.name', null)
        ->assertJsonPath('data.0.broadcast_channel', $chat['broadcast_channel'])
        ->assertJsonPath('meta.inbox_channel', 'tenant.'.$this->tenant->id.'.support-inbox')
        ->assertJsonPath('meta.presence_channel', 'tenant.'.$this->tenant->id.'.support-agents-online');
    $this->tenantJson('GET', '/api/admin/support/conversations', [], $this->cashierAuth)->assertForbidden();
    $this->tenantJson('PATCH', "/api/admin/support/conversations/{$chat['id']}", ['assigned_to_user_id' => $this->cashier->id], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'agent_not_eligible');
    $this->tenantJson('PATCH', "/api/admin/support/conversations/{$chat['id']}", ['assigned_to_user_id' => $this->owner->id, 'priority' => 'high'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'priority_tickets_only');
    $this->tenantJson('PATCH', "/api/admin/support/conversations/{$chat['id']}", ['assigned_to_user_id' => $this->owner->id], $this->staff)->assertOk()
        ->assertJsonPath('data.assignee.id', $this->owner->id);

    // A reply waits for the customer; a note stays with staff.
    $this->tenantJson('POST', "/api/admin/support/conversations/{$chat['id']}/messages", ['body' => 'Yes, in 3 days.'], $this->staff)->assertCreated();
    $this->tenantJson('POST', "/api/admin/support/conversations/{$chat['id']}/notes", ['body' => 'Check the courier rate first'], $this->staff)->assertCreated()
        ->assertJsonPath('data.is_internal_note', true);
    Event::assertDispatched(SupportMessageSent::class, fn (SupportMessageSent $e): bool => $e->internal && count($e->broadcastOn()) === 1
        && $e->broadcastOn()[0]->name === 'private-tenant.'.$this->tenant->id.'.support-inbox');
    Event::assertDispatched(SupportConversationStatusChanged::class, fn (SupportConversationStatusChanged $e): bool => $e->status === 'pending');
    $this->tenantJson('GET', "/api/admin/support/conversations/{$chat['id']}", [], $this->staff)->assertOk()->assertJsonCount(3, 'data.messages');
    $mine = $this->tenantJson('GET', "/api/support/conversations/{$chat['id']}", [], $guest)->assertOk()->assertJsonPath('data.status', 'pending')
        ->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.1.sender_name', 'Tola')->json('data');
    expect(collect($mine['messages'])->pluck('body'))->not->toContain('Check the courier rate first');

    // Read receipts on both sides.
    $this->tenantJson('POST', "/api/support/conversations/{$chat['id']}/read", [], $guest)->assertOk()->assertJsonPath('data.marked', 1);
    $this->tenantJson('POST', "/api/admin/support/conversations/{$chat['id']}/read", [], $this->staff)->assertOk()->assertJsonPath('data.marked', 1);

    // Channels: the guest whose token matches, agents; nobody else.
    tenancy()->initialize($this->tenant);
    expect(supportChannelAllows('support-conversation.{conversationId}', new GuestSupportActor($chat['guest_token']), $chat['id']))->toBeTrue()
        ->and(supportChannelAllows('support-conversation.{conversationId}', new GuestSupportActor((string) Str::uuid()), $chat['id']))->toBeFalse()
        ->and(supportChannelAllows('support-conversation.{conversationId}', $this->owner, $chat['id']))->toBeTrue()
        ->and(supportChannelAllows('support-conversation.{conversationId}', $this->cashier, $chat['id']))->toBeFalse()
        ->and(supportChannelAllows('support-inbox', $this->owner))->toBeTrue()
        ->and(supportChannelAllows('support-inbox', $this->ada))->toBeFalse();

    // Signing in with the guest token takes the chat along.
    $this->tenantJson('POST', '/api/auth/login', ['email' => 'ada@shop.test', 'password' => 'Secret123'], $guest)->assertOk();
    $this->tenantJson('GET', '/api/support/conversations', [], $this->adaAuth)->assertOk()->assertJsonPath('data.0.id', $chat['id']);
    $this->tenantJson('GET', '/api/support/conversations', [], $guest)->assertOk()->assertJsonCount(0, 'data');
    tenancy()->initialize($this->tenant);
    expect(supportChannelAllows('support-conversation.{conversationId}', $this->ada, $chat['id']))->toBeTrue();
});

it('runs tickets with attachments, emails replies to guests, reopens on reply and refuses a closed conversation', function (): void {
    // A guest ticket needs a name and an email.
    $this->tenantJson('POST', '/api/support/conversations', ['channel' => 'ticket', 'subject' => 'Refund', 'body' => 'Where is my refund?'])
        ->assertStatus(422)->assertJsonValidationErrors(['guest_name', 'guest_email']);
    $guestTicket = $this->tenantJson('POST', '/api/support/conversations', ['channel' => 'ticket', 'subject' => 'Refund', 'body' => 'Where is my refund?',
        'guest_name' => 'Chika', 'guest_email' => 'Chika@Mail.test'])->assertCreated()->assertJsonPath('data.priority', 'normal')->json('data');
    $this->tenantJson('POST', "/api/admin/support/conversations/{$guestTicket['id']}/messages", ['body' => 'It was sent yesterday.'], $this->staff)->assertCreated();
    Notification::assertSentOnDemand(TemplatedNotification::class, fn (TemplatedNotification $n, array $channels, AnonymousNotifiable $to): bool => $n->key === 'support.reply_received'
        && $to->routes['mail'] === 'chika@mail.test' && str_contains($n->body, 'sent yesterday'));

    // A customer's ticket with a file; only Ada and staff can fetch it.
    $ticket = $this->tenantJson('POST', '/api/support/conversations', ['channel' => 'ticket', 'subject' => 'Damaged item', 'body' => 'The mug arrived cracked.'], $this->adaAuth)
        ->assertCreated()->assertJsonPath('data.guest_token', null)->json('data');
    $photo = $this->tenantJson('POST', "/api/support/conversations/{$ticket['id']}/messages", ['body' => 'Photo attached',
        'attachments' => [UploadedFile::fake()->create('crack.pdf', 30, 'application/pdf')]], $this->adaAuth)->assertCreated()->json('data');
    $path = $photo['attachments'][0]['download_path'];
    $this->withHeaders($this->adaAuth)->get('http://'.$this->tenantHost().$path)->assertOk()->assertDownload('crack.pdf');
    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->bolaAuth)->get('http://'.$this->tenantHost().$path)->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->staff)->get('http://'.$this->tenantHost()."/api/admin/support/conversations/{$ticket['id']}/attachments/{$photo['attachments'][0]['id']}")->assertOk();

    // Resolved, then reopened by the customer's reply; closed refuses messages.
    $this->tenantJson('PATCH', "/api/admin/support/conversations/{$ticket['id']}", ['status' => 'resolved', 'priority' => 'high'], $this->staff)->assertOk()
        ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.priority', 'high');
    $this->tenantJson('POST', "/api/support/conversations/{$ticket['id']}/messages", ['body' => 'Still waiting'], $this->adaAuth)->assertCreated();
    $this->tenantJson('GET', "/api/support/conversations/{$ticket['id']}", [], $this->adaAuth)->assertOk()->assertJsonPath('data.status', 'open');
    $this->tenantJson('PATCH', "/api/admin/support/conversations/{$ticket['id']}", ['status' => 'closed'], $this->staff)->assertOk();
    $this->tenantJson('POST', "/api/support/conversations/{$ticket['id']}/messages", ['body' => 'Hello?'], $this->adaAuth)->assertStatus(422)
        ->assertJsonPath('meta.error_code', 'conversation_closed');

    // The section and the strip.
    $strip = collect($this->tenantJson('GET', '/api/admin/support/conversations/metrics?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($strip)->toMatchArray(['open' => 0, 'pending' => 1, 'unassigned' => 1, 'resolved' => 1]);
    $section = collect($this->tenantJson('GET', '/api/admin/dashboard/support?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->pluck('value', 'key')->all();
    expect($section)->toMatchArray(['open' => 1, 'unassigned' => 1, 'resolved' => 1]);

    // Erasure removes the customer's conversations.
    tenancy()->initialize($this->tenant);
    app(CustomerService::class)->deleteCustomer($this->ada);
    expect(SupportConversation::query()->where('id', $ticket['id'])->exists())->toBeFalse()
        ->and(SupportConversation::query()->where('id', $guestTicket['id'])->exists())->toBeTrue();
});
