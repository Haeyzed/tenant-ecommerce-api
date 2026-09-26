<?php

declare(strict_types=1);

namespace App\Shared\Messaging\WhatsApp;

use App\Shared\Messaging\Contracts\WhatsAppGatewayInterface;
use App\Shared\Messaging\PhoneMask;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Business Cloud API (spec §16.4).
 */
final readonly class WhatsAppBusinessGateway implements WhatsAppGatewayInterface
{
    public function __construct(
        private string $phoneNumberId,
        private string $accessToken,
        private string $baseUrl = 'https://graph.facebook.com',
        private string $apiVersion = 'v21.0',
        private int $timeout = 10,
    ) {}

    public function send(string $to, string $message, array $mediaUrls = []): bool
    {
        $recipient = ltrim($to, '+');

        if (! $this->post(['to' => $recipient, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $message]], $to)) {
            return false;
        }

        foreach ($mediaUrls as $url) {
            if (! $this->post(['to' => $recipient, 'type' => 'image', 'image' => ['link' => $url]], $to)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload, string $to): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withToken($this->accessToken)
                ->acceptJson()
                ->post("/{$this->apiVersion}/{$this->phoneNumberId}/messages", ['messaging_product' => 'whatsapp'] + $payload);
        } catch (ConnectionException $e) {
            Log::warning('WhatsApp connection failed.', ['to' => PhoneMask::mask($to), 'error' => $e->getMessage()]);

            return false;
        }

        if ($response->successful() && filled($response->json('messages.0.id'))) {
            return true;
        }

        Log::warning('WhatsApp message rejected.', [
            'to' => PhoneMask::mask($to),
            'status' => $response->status(),
            'error_code' => $response->json('error.code'),
        ]);

        return false;
    }
}
