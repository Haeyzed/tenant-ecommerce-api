<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Channels;

use App\Modules\Messaging\Support\ChannelTransports;
use App\Modules\Messaging\Support\SmsGatewayFactory;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use RuntimeException;

final readonly class SmsChannel
{
    public function __construct(private SmsGatewayFactory $factory) {}

    public function send(object $notifiable, TemplatedNotification $notification): void
    {
        $phone = ChannelTransports::phone($notifiable);

        if (blank($phone)) {
            return;
        }

        $gateway = $notification->scope === NotificationScope::Tenant
            ? $this->factory->forTenant()
            : $this->factory->forPlatform();

        // The gateway was removed after dispatch: nothing to deliver with.
        if ($gateway === null) {
            return;
        }

        if (! $gateway->send((string) $phone, $notification->text())) {
            throw new RuntimeException("SMS delivery of [{$notification->key}] failed.");
        }
    }
}
