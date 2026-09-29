<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

use App\Shared\Support\TenantChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A conversation's status changed (spec §59.2).
 */
final class SupportConversationStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public string $queue = 'tenant-default';

    public function __construct(
        public readonly string $tenantId,
        public readonly int $conversationId,
        public readonly string $status,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(TenantChannel::name('support-conversation.'.$this->conversationId, $this->tenantId)),
            new PrivateChannel(TenantChannel::name('support-inbox', $this->tenantId)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.status';
    }

    /**
     * @return array{conversation_id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'status' => $this->status];
    }
}
