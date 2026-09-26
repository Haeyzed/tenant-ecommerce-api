<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Messaging\Channels\PushChannel;
use App\Modules\Messaging\Channels\SmsChannel;
use App\Modules\Messaging\Channels\TemplatedMailChannel;
use App\Modules\Messaging\Channels\WhatsAppChannel;
use App\Modules\Notifications\Enums\NotificationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One rendered catalog notification for one recipient (spec §17.5). The
 * channels were already filtered through the delivery rules (§17.3) by
 * NotificationDispatchService; this class only transports the content.
 */
final class TemplatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $data  non-sensitive context for the in-app inbox and push payload
     */
    public function __construct(
        public readonly string $key,
        public readonly NotificationScope $scope,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly array $channels,
        public readonly array $data = [],
    ) {
        $this->onQueue($scope->queue());
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_map(static fn (string $channel): string => match ($channel) {
            'database' => 'database',
            'email' => TemplatedMailChannel::class,
            'sms' => SmsChannel::class,
            'whatsapp' => WhatsAppChannel::class,
            'push' => PushChannel::class,
        }, $this->channels));
    }

    public function databaseType(object $notifiable): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'key' => $this->key,
            'subject' => $this->subject,
            'body' => $this->body,
            'data' => $this->data,
        ];
    }

    public function text(): string
    {
        return $this->subject === null || $this->subject === ''
            ? $this->body
            : $this->subject."\n\n".$this->body;
    }
}
