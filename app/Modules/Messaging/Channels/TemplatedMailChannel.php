<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Channels;

use App\Modules\Messaging\Mail\TemplatedMail;
use App\Modules\Messaging\Services\MailService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;

final readonly class TemplatedMailChannel
{
    public function __construct(private MailService $mail) {}

    public function send(object $notifiable, TemplatedNotification $notification): void
    {
        $address = $notifiable->routeNotificationFor('mail', $notification);

        if (blank($address)) {
            return;
        }

        $this->mail->send(
            new TemplatedMail((string) ($notification->subject ?? ''), $notification->body),
            $address,
            platform: $notification->scope === NotificationScope::Landlord,
        );
    }
}
