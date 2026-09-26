<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Sms;

use App\Shared\Messaging\Contracts\SmsGatewayInterface;
use App\Shared\Messaging\PhoneMask;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Termii SMS (spec §16.3). Credentials: {api_key, sender_id}.
 */
final readonly class TermiiGateway implements SmsGatewayInterface
{
    public function __construct(
        private string $apiKey,
        private string $senderId,
        private string $baseUrl = 'https://api.ng.termii.com',
        private int $timeout = 10,
    ) {}

    public function send(string $to, string $message): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post('/api/sms/send', [
                    'api_key' => $this->apiKey,
                    'to' => ltrim($to, '+'),
                    'from' => $this->senderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => 'generic',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Termii SMS connection failed.', ['to' => PhoneMask::mask($to), 'error' => $e->getMessage()]);

            return false;
        }

        if ($response->successful() && filled($response->json('message_id'))) {
            return true;
        }

        Log::warning('Termii SMS rejected.', ['to' => PhoneMask::mask($to), 'status' => $response->status(), 'code' => $response->json('code')]);

        return false;
    }
}
