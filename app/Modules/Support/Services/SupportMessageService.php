<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Support\Events\SupportConversationStatusChanged;
use App\Modules\Support\Events\SupportMessageSent;
use App\Modules\Support\Models\SupportConversation;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportMessageAttachment;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Messages of support conversations (spec §59.3). A customer message
 * reopens a resolved conversation; a staff reply waits for the customer
 * (pending). A closed conversation takes no messages.
 */
final readonly class SupportMessageService
{
    /**
     * A recipient is notified at most once per conversation and side in
     * this window; a live conversation reaches them through the broadcast
     * instead (the "not connected" rule of §59.3).
     */
    private const int NOTIFY_WINDOW_MINUTES = 15;

    public const int MAX_ATTACHMENTS = 5;

    public function __construct(
        private NotificationDispatchService $notifications,
        private TenantSettingsService $settings,
        private StorageQuota $quota,
    ) {}

    /**
     * @param  array{sender_type: string, sender_id: int|null, body: string}  $data
     * @param  list<UploadedFile>  $attachments
     */
    public function sendMessage(SupportConversation $conversation, array $data, array $attachments = []): SupportMessage
    {
        validator(['body' => $data['body'], 'attachments' => $attachments], [
            'body' => ['required', 'string', 'max:10000'],
            'attachments' => ['array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => UploadRules::document(),
        ])->validate();

        if ($attachments !== []) {
            $this->quota->assertAllows($attachments);
        }

        $customerSide = in_array($data['sender_type'], [SupportMessage::CUSTOMER, SupportMessage::GUEST], true);
        $statusChanged = null;

        $message = DB::connection('tenant')->transaction(function () use ($conversation, $data, $attachments, $customerSide, &$statusChanged): SupportMessage {
            /** @var SupportConversation $locked */
            $locked = SupportConversation::query()->lockForUpdate()->findOrFail($conversation->id);

            if ($locked->status === SupportConversation::CLOSED) {
                throw ApiException::unprocessable('conversation_closed', 'This conversation is closed. Start a new one.');
            }

            $message = new SupportMessage;
            $message->forceFill([
                'support_conversation_id' => $locked->id,
                'sender_type' => $data['sender_type'],
                'sender_id' => $data['sender_id'],
                'body' => $data['body'],
                'is_internal_note' => false,
            ])->save();

            $status = $customerSide ? SupportConversation::OPEN : SupportConversation::PENDING;
            $statusChanged = $locked->status !== $status ? $status : null;
            $locked->forceFill(['last_message_at' => now(), 'status' => $status])->save();
            $conversation->setRawAttributes($locked->getAttributes(), true);

            $this->attach($message, $attachments);

            return $message;
        });

        event(SupportMessageSent::from($message));

        if ($statusChanged !== null) {
            event(new SupportConversationStatusChanged((string) tenant()?->getTenantKey(), $conversation->id, $statusChanged));
        }

        $this->notifyRecipient($conversation, $message);

        return $message;
    }

    public function addInternalNote(SupportConversation $conversation, User $staff, string $body): SupportMessage
    {
        validator(['body' => $body], ['body' => ['required', 'string', 'max:10000']])->validate();

        $note = new SupportMessage;
        $note->forceFill([
            'support_conversation_id' => $conversation->id,
            'sender_type' => SupportMessage::STAFF,
            'sender_id' => $staff->id,
            'body' => $body,
            'is_internal_note' => true,
        ])->save();

        event(SupportMessageSent::from($note));

        return $note;
    }

    /**
     * Marks the other side's messages up to $message as read.
     *
     * @param  bool  $staffReader  true when staff read the customer's messages
     */
    public function markRead(SupportMessage $message, bool $staffReader): int
    {
        return SupportMessage::query()
            ->where('support_conversation_id', $message->support_conversation_id)
            ->where('id', '<=', $message->id)
            ->where('is_internal_note', false)
            ->whereIn('sender_type', $staffReader ? [SupportMessage::CUSTOMER, SupportMessage::GUEST] : [SupportMessage::STAFF, SupportMessage::SYSTEM])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function attach(SupportMessage $message, array $files): void
    {
        foreach ($files as $file) {
            $attachment = new SupportMessageAttachment;
            $attachment->forceFill(['support_message_id' => $message->id])->save();
            $attachment->addMedia($file)
                ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                ->usingName(mb_substr($file->getClientOriginalName(), 0, 200))
                ->toMediaCollection('attachment');
        }
    }

    /**
     * support.message_received to the assignee (or everyone who sees the
     * inbox) for a customer message; support.reply_received to the customer
     * (or the guest's email) for a staff reply.
     */
    private function notifyRecipient(SupportConversation $conversation, SupportMessage $message): void
    {
        $side = $message->fromCustomerSide() ? 'customer' : 'staff';

        if (! Cache::add('support-notified:'.tenant()?->getTenantKey().':'.$conversation->id.':'.$side, true, now()->addMinutes(self::NOTIFY_WINDOW_MINUTES))) {
            return;
        }

        $conversation->loadMissing(['customer', 'assignee']);
        $excerpt = mb_strimwidth($message->body, 0, 280, '…');
        $customerName = $conversation->customer?->name ?? $conversation->guest_name ?? 'A guest';

        if ($side === 'customer') {
            $recipients = $conversation->assignee !== null && $conversation->assignee->is_active
                ? collect([$conversation->assignee])
                : User::query()->where('is_active', true)->get()->filter(static fn (User $u): bool => $u->hasPermissionTo(SupportConversationService::VIEW, 'staff'))->values();

            if ($recipients->isNotEmpty()) {
                $this->notifications->dispatch('support.message_received', $recipients->all(), [
                    'customer_name' => $customerName,
                    'message_excerpt' => $excerpt,
                ], data: ['conversation_id' => $conversation->id]);
            }

            return;
        }

        $variables = [
            'customer_name' => $customerName,
            'store_name' => (string) $this->settings->get('store_name', ''),
            'message_excerpt' => $excerpt,
        ];

        if ($conversation->customer !== null && $conversation->customer->anonymized_at === null) {
            $this->notifications->dispatch('support.reply_received', $conversation->customer, $variables, data: ['conversation_id' => $conversation->id]);
        } elseif ($conversation->customer_id === null && $conversation->guest_email !== null) {
            $this->notifications->dispatch('support.reply_received', Notification::route('mail', $conversation->guest_email), $variables);
        }
    }
}
