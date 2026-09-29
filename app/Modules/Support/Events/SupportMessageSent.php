<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

use App\Modules\Support\Models\SupportMessage;
use App\Shared\Support\TenantChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new message (spec §59.2). A visible message reaches the conversation
 * channel (the customer or guest, and staff) and the staff inbox; an
 * internal note reaches the staff inbox only.
 */
final class SupportMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $queue = 'tenant-default';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $conversationId,
        public readonly bool $internal,
        public readonly array $payload,
    ) {}

    public static function from(SupportMessage $message): self
    {
        return new self((string) tenant()?->getTenantKey(), $message->support_conversation_id, $message->is_internal_note, [
            'id' => $message->id,
            'conversation_id' => $message->support_conversation_id,
            'sender_type' => $message->sender_type,
            'body' => $message->body,
            'is_internal_note' => $message->is_internal_note,
            'created_at' => $message->created_at->toIso8601String(),
        ]);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $inbox = new PrivateChannel(TenantChannel::name('support-inbox', $this->tenantId));

        return $this->internal ? [$inbox] : [new PrivateChannel(TenantChannel::name('support-conversation.'.$this->conversationId, $this->tenantId)), $inbox];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
