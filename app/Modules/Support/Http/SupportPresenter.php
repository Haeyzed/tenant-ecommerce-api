<?php

declare(strict_types=1);

namespace App\Modules\Support\Http;

use App\Modules\Customers\Models\Customer;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportMessageAttachment;
use App\Modules\Users\Models\User;
use App\Shared\Support\TenantChannel;

/**
 * Support payloads (spec §59). The customer view never contains internal
 * notes or the assignee's identity beyond a first name.
 */
final class SupportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function conversation(SupportConversation $conversation, bool $admin, bool $withMessages = false): array
    {
        $payload = [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'subject' => $conversation->subject,
            'status' => $conversation->status,
            'priority' => $conversation->priority,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'created_at' => $conversation->created_at->toIso8601String(),
            // For Echo.private() (BG-05): Echo adds the "private-" prefix.
            'broadcast_channel' => TenantChannel::name('support-conversation.'.$conversation->id),
        ];

        if ($admin) {
            $conversation->loadMissing(['customer:id,name,email', 'assignee:id,name']);
            $payload['customer'] = $conversation->customer === null ? null : ['id' => $conversation->customer->id, 'name' => $conversation->customer->name, 'email' => $conversation->customer->email];
            $payload['guest'] = $conversation->customer_id === null ? ['name' => $conversation->guest_name, 'email' => $conversation->guest_email] : null;
            $payload['assignee'] = $conversation->assignee === null ? null : ['id' => $conversation->assignee->id, 'name' => $conversation->assignee->name];
            $payload['unread_count'] = $conversation->getAttributes()['unread_count'] ?? null;
        }

        if ($withMessages) {
            $messages = $conversation->messages()->with('attachments.media')
                ->when(! $admin, static fn ($q) => $q->where('is_internal_note', false))->get();
            $names = $this->senderNames($messages->all());
            $payload['messages'] = $messages->map(fn (SupportMessage $m): array => $this->message($m, $admin, $names))->all();
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $names  "type:id" => display name
     * @return array<string, mixed>
     */
    public function message(SupportMessage $message, bool $admin, array $names = []): array
    {
        $message->loadMissing('attachments.media');
        $sender = $names[$message->sender_type.':'.$message->sender_id] ?? null;

        return [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            // Customers see a staff member's first name only.
            'sender_name' => $sender !== null && ! $admin && $message->sender_type === SupportMessage::STAFF ? strtok($sender, ' ') : $sender,
            'body' => $message->body,
            'is_internal_note' => $admin ? $message->is_internal_note : null,
            'read_at' => $message->read_at?->toIso8601String(),
            'attachments' => $message->attachments->map(static function (SupportMessageAttachment $a) use ($message, $admin): array {
                $media = $a->getFirstMedia('attachment');

                return [
                    'id' => $a->id,
                    'name' => $media?->name,
                    'size' => $media?->size,
                    'mime_type' => $media?->mime_type,
                    'download_path' => ($admin ? '/api/admin' : '/api').'/support/conversations/'.$message->support_conversation_id.'/attachments/'.$a->id,
                ];
            })->all(),
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  list<SupportMessage>  $messages
     * @return array<string, string>
     */
    private function senderNames(array $messages): array
    {
        $staff = array_unique(array_filter(array_map(static fn (SupportMessage $m): ?int => $m->sender_type === SupportMessage::STAFF ? $m->sender_id : null, $messages)));
        $customers = array_unique(array_filter(array_map(static fn (SupportMessage $m): ?int => $m->sender_type === SupportMessage::CUSTOMER ? $m->sender_id : null, $messages)));
        $names = [];

        foreach (User::query()->whereKey($staff)->get(['id', 'name']) as $user) {
            $names['staff:'.$user->id] = $user->name;
        }

        foreach (Customer::query()->withTrashed()->whereKey($customers)->get(['id', 'name']) as $customer) {
            $names['customer:'.$customer->id] = $customer->name;
        }

        return $names;
    }
}
