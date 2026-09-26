<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Contracts;

/**
 * An SMS provider driver (spec §16.3).
 */
interface SmsGatewayInterface
{
    /**
     * True when the provider accepted the message.
     */
    public function send(string $to, string $message): bool;
}
