<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\PlatformSupport\Events\PlatformSupportMessageSent;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\PlatformSupport\Models\PlatformSupportMessage;
use App\Modules\PlatformSupport\Models\PlatformSupportMessageAttachment;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\UploadRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Messages of platform support conversations (spec §21.3).
 */
final readonly class PlatformSupportMessageService
{
    /**
     * A recipient is emailed at most once per conversation in this window;
     * a live conversation reaches them through the broadcast instead.
     */
    private const int NOTIFY_WINDOW_MINUTES = 15;

    public const int MAX_ATTACHMENTS = 5;

    public function __construct(private NotificationDispatchService $notifications) {}

    /**
     * @param  array{sender_type: string, sender_id: int|null, sender_label: string, body: string}  $data
     * @param  list<UploadedFile>  $attachments
     */
    public function sendMessage(PlatformSupportConversation $conversation, array $data, array $attachments = []): PlatformSupportMessage
    {
        validator(['body' => $data['body'], 'attachments' => $attachments], [
            'body' => ['required', 'string', 'max:10000'],
            'attachments' => ['array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => UploadRules::document(),
        ])->validate();

        if ($conversation->status === 'closed') {
            throw ApiException::unprocessable('conversation_closed', 'This conversation is closed. Open a new one.');
        }

        $message = DB::connection('landlord')->transaction(static function () use ($conversation, $data): PlatformSupportMessage {
            /** @var PlatformSupportMessage $message */
            $message = $conversation->messages()->create([
                'sender_type' => $data['sender_type'],
                'sender_id' => $data['sender_id'],
                'sender_label' => $data['sender_label'],
                'body' => $data['body'],
                'is_internal_note' => false,
            ]);

            $conversation->forceFill([
                'last_message_at' => now(),
                // A tenant reply reopens a resolved conversation; a staff
                // reply waits for the tenant.
                'status' => $data['sender_type'] === PlatformSupportMessage::TENANT ? 'open' : 'pending',
            ])->save();

            return $message;
        });

        $this->attach($message, $attachments);

        event(PlatformSupportMessageSent::from($message));

        $this->notifyRecipient($conversation, $message);

        return $message;
    }

    public function addInternalNote(PlatformSupportConversation $conversation, PlatformUser $staff, string $body): PlatformSupportMessage
    {
        validator(['body' => $body], ['body' => ['required', 'string', 'max:10000']])->validate();

        /** @var PlatformSupportMessage $note */
        $note = $conversation->messages()->create([
            'sender_type' => PlatformSupportMessage::PLATFORM_USER,
            'sender_id' => $staff->id,
            'sender_label' => $staff->name,
            'body' => $body,
            'is_internal_note' => true,
        ]);

        return $note;
    }

    /**
     * Marks the other side's messages up to $message as read.
     */
    public function markRead(PlatformSupportMessage $message, string $readerSide): void
    {
        PlatformSupportMessage::query()
            ->where('platform_support_conversation_id', $message->platform_support_conversation_id)
            ->where('id', '<=', $message->id)
            ->where('sender_type', '!=', $readerSide)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Files are stored in the landlord media table and disk, even when the
     * message comes from a tenant request.
     *
     * @param  list<UploadedFile>  $files
     */
    private function attach(PlatformSupportMessage $message, array $files): void
    {
        if ($files === []) {
            return;
        }

        $store = static function () use ($message, $files): void {
            foreach ($files as $file) {
                /** @var PlatformSupportMessageAttachment $attachment */
                $attachment = $message->attachments()->create();
                $attachment->addMedia($file)
                    ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                    ->usingName(mb_substr($file->getClientOriginalName(), 0, 200))
                    ->toMediaCollection('attachment');
            }
        };

        tenancy()->initialized ? tenancy()->central($store) : $store();
    }

    private function notifyRecipient(PlatformSupportConversation $conversation, PlatformSupportMessage $message): void
    {
        $key = 'platform-support-notified:'.$conversation->id.':'.$message->sender_type;

        if (! Cache::store('landlord')->add($key, true, now()->addMinutes(self::NOTIFY_WINDOW_MINUTES))) {
            return;
        }

        $tenant = Tenant::query()->find($conversation->tenant_id);
        $excerpt = mb_strimwidth($message->body, 0, 280, '…');

        if ($message->sender_type === PlatformSupportMessage::TENANT) {
            $recipients = $conversation->assigned_to !== null
                ? PlatformUser::query()->whereKey($conversation->assigned_to)->where('is_active', true)->get()
                : PlatformUser::query()->withPlatformRole('support-staff')->where('is_active', true)->get();

            $this->notifications->dispatch('platform_support.message_received', $recipients, [
                'tenant_name' => (string) $tenant?->name,
                'subject' => $conversation->subject,
                'message_excerpt' => $excerpt,
            ], data: ['conversation_id' => $conversation->id]);

            return;
        }

        if ($tenant !== null) {
            $this->notifications->dispatch('platform_support.reply_received', $tenant, [
                'owner_name' => $tenant->owner_name,
                'subject' => $conversation->subject,
                'message_excerpt' => $excerpt,
            ]);
        }
    }
}
