<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Channels;

use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Shared\Messaging\PushNotificationService;
use Illuminate\Database\Eloquent\Model;

final readonly class PushChannel
{
    public function __construct(private PushNotificationService $push) {}

    public function send(object $notifiable, TemplatedNotification $notification): void
    {
        if (! $notifiable instanceof Model || ! PushNotificationService::isConfigured()) {
            return;
        }

        $data = array_filter(
            ['key' => $notification->key] + $notification->data,
            static fn (mixed $value): bool => is_scalar($value),
        );

        $this->push->sendToTenantUser($notifiable, (string) ($notification->subject ?? ''), $notification->body, $data);
    }
}
