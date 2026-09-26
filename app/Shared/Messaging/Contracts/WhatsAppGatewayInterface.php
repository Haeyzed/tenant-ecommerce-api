<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Contracts;

/**
 * A WhatsApp provider driver (spec §16.4).
 */
interface WhatsAppGatewayInterface
{
    /**
     * @param  list<string>  $mediaUrls  public image URLs sent after the text
     */
    public function send(string $to, string $message, array $mediaUrls = []): bool;
}
