<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Events;

use App\Modules\PlatformSupport\Models\PlatformSupportMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new visible message in a conversation (spec §21.2). Internal notes are
 * never broadcast on the conversation channel, which tenant staff join.
 */
final class PlatformSupportMessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $queue = 'landlord-default';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $payload,
    ) {}

    public static function from(PlatformSupportMessage $message): self
    {
        return new self($message->platform_support_conversation_id, [
            'id' => $message->id,
            'conversation_id' => $message->platform_support_conversation_id,
            'sender_type' => $message->sender_type,
            'sender_label' => $message->sender_label,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ]);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('platform-support-conversation.'.$this->conversationId);
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
