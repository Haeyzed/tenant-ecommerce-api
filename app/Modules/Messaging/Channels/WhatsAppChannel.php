<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Channels;

use App\Modules\Messaging\Services\WhatsAppSettingsService;
use App\Modules\Messaging\Support\ChannelTransports;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use RuntimeException;

final readonly class WhatsAppChannel
{
    public function __construct(private WhatsAppSettingsService $settings) {}

    public function send(object $notifiable, TemplatedNotification $notification): void
    {
        $phone = ChannelTransports::phone($notifiable);
        $gateway = $this->settings->gateway();

        if (blank($phone) || $gateway === null) {
            return;
        }

        if (! $gateway->send((string) $phone, $notification->text())) {
            throw new RuntimeException("WhatsApp delivery of [{$notification->key}] failed.");
        }
    }
}
