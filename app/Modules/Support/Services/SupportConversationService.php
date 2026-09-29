<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Support\Events\SupportConversationStatusChanged;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Support conversations (spec §59.3): tickets and live chats, opened by a
 * customer or a guest (bound to the storefront guest token).
 */
final readonly class SupportConversationService
{
    public const string VIEW = 'support.conversations.view';

    public function __construct(private SupportMessageService $messages) {}

    /**
     * @param  array<string, mixed>  $data  subject, body, priority?, guest_name?, guest_email (guests)
     */
    public function startTicket(?Customer $customer, array $data, ?string $guestToken = null): SupportConversation
    {
        $validated = Validator::make($data, [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'guest_name' => [$customer === null ? 'required' : 'prohibited', 'string', 'max:120'],
            'guest_email' => [$customer === null ? 'required' : 'prohibited', 'email:rfc', 'max:255'],
        ])->validate();

        return $this->open($customer, $guestToken, SupportConversation::TICKET, $validated, 'normal');
    }

    /**
     * Opens empty unless a first message is given.
     *
     * @param  array<string, mixed>  $data  body?, guest_name?, guest_email?
     */
    public function startChat(?Customer $customer, array $data = [], ?string $guestToken = null): SupportConversation
    {
        $validated = Validator::make($data, [
            'body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'guest_name' => [$customer === null ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:120'],
            'guest_email' => [$customer === null ? 'sometimes' : 'prohibited', 'nullable', 'email:rfc', 'max:255'],
        ])->validate();

        return $this->open($customer, $guestToken, SupportConversation::CHAT, $validated, null);
    }

    /**
     * An active staff user who can see the inbox.
     */
    public function assignToAgent(SupportConversation $conversation, ?User $agent): SupportConversation
    {
        if ($agent !== null && (! $agent->is_active || ! $agent->hasPermissionTo(self::VIEW, 'staff'))) {
            throw ApiException::unprocessable('agent_not_eligible', 'This user cannot handle support conversations.');
        }

        $conversation->forceFill(['assigned_to_user_id' => $agent?->id])->save();

        return $conversation;
    }

    public function updateStatus(SupportConversation $conversation, string $status): SupportConversation
    {
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(SupportConversation::STATUSES)]])->validate();

        if ($conversation->status !== $status) {
            $conversation->forceFill(['status' => $status])->save();
            event(new SupportConversationStatusChanged((string) tenant()?->getTenantKey(), $conversation->id, $status));
        }

        return $conversation;
    }

    public function updatePriority(SupportConversation $conversation, string $priority): SupportConversation
    {
        Validator::make(['priority' => $priority], ['priority' => ['required', Rule::in(SupportConversation::PRIORITIES)]])->validate();

        if ($conversation->channel !== SupportConversation::TICKET) {
            throw ApiException::unprocessable('priority_tickets_only', 'Only tickets have a priority.');
        }

        $conversation->forceFill(['priority' => $priority])->save();

        return $conversation;
    }

    /**
     * The admin inbox, most recent activity first.
     *
     * @param  array{status?: string, channel?: string, assigned_to?: string, customer_id?: int, per_page?: int}  $filters  assigned_to: me | unassigned | a user id
     * @return LengthAwarePaginator<int, SupportConversation>
     */
    public function listConversations(array $filters, User $viewer): LengthAwarePaginator
    {
        $assigned = $filters['assigned_to'] ?? null;

        return SupportConversation::query()->with(['customer:id,name,email', 'assignee:id,name'])
            ->withCount(['messages as unread_count' => static fn ($q) => $q->whereIn('sender_type', [SupportMessage::CUSTOMER, SupportMessage::GUEST])->whereNull('read_at')])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['channel']), static fn ($q) => $q->where('channel', $filters['channel']))
            ->when(isset($filters['customer_id']), static fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when($assigned === 'me', static fn ($q) => $q->where('assigned_to_user_id', $viewer->id))
            ->when($assigned === 'unassigned', static fn ($q) => $q->whereNull('assigned_to_user_id'))
            ->when($assigned !== null && ctype_digit((string) $assigned), static fn ($q) => $q->where('assigned_to_user_id', (int) $assigned))
            ->orderByRaw('last_message_at IS NULL')->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return Collection<int, SupportConversation>
     */
    public function getForCustomer(Customer $customer): Collection
    {
        return SupportConversation::query()->where('customer_id', $customer->id)->orderByDesc('last_message_at')->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, SupportConversation>
     */
    public function getForGuest(string $guestToken): Collection
    {
        return SupportConversation::query()->whereNull('customer_id')->where('guest_token', $guestToken)->orderByDesc('last_message_at')->orderByDesc('id')->get();
    }

    /**
     * §59.1: a guest who signs in with the token takes their conversations along.
     */
    public function claimGuestConversations(string $guestToken, Customer $customer): int
    {
        return SupportConversation::query()->whereNull('customer_id')->where('guest_token', $guestToken)
            ->update(['customer_id' => $customer->id, 'guest_token' => null, 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function open(?Customer $customer, ?string $guestToken, string $channel, array $validated, ?string $priority): SupportConversation
    {
        if ($customer === null && $guestToken === null) {
            throw ApiException::unprocessable('guest_token_required', 'Send the X-Guest-Token header, or sign in.');
        }

        $conversation = DB::connection('tenant')->transaction(static function () use ($customer, $guestToken, $channel, $validated, $priority): SupportConversation {
            $conversation = new SupportConversation;
            $conversation->forceFill([
                'customer_id' => $customer?->id,
                'guest_token' => $customer === null ? $guestToken : null,
                'guest_name' => $validated['guest_name'] ?? null,
                'guest_email' => isset($validated['guest_email']) ? strtolower((string) $validated['guest_email']) : null,
                'channel' => $channel,
                'subject' => $validated['subject'] ?? null,
                'status' => SupportConversation::OPEN,
                'priority' => $priority,
            ])->save();

            return $conversation;
        });

        if (($validated['body'] ?? null) !== null && $validated['body'] !== '') {
            $this->messages->sendMessage($conversation, [
                'sender_type' => $customer === null ? SupportMessage::GUEST : SupportMessage::CUSTOMER,
                'sender_id' => $customer?->id,
                'body' => (string) $validated['body'],
            ]);
        }

        return $conversation->refresh();
    }
}
