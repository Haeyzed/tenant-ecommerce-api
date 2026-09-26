<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A conversation's status or priority changed (spec §21.2).
 */
final class PlatformSupportConversationStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $queue = 'landlord-default';

    public function __construct(
        public readonly int $conversationId,
        public readonly string $status,
        public readonly string $priority,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('platform-support-conversation.'.$this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'conversation.status_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'status' => $this->status, 'priority' => $this->priority];
    }
}
