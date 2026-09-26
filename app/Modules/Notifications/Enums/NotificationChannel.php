<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * The five delivery channels (spec §16.1).
 */
enum NotificationChannel: string
{
    case Database = 'database';
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Push = 'push';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
