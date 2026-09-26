<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Sms;

use App\Shared\Messaging\Contracts\SmsGatewayInterface;
use App\Shared\Messaging\PhoneMask;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Africa's Talking SMS (spec §16.3). Credentials: {username, api_key,
 * sender_id?}.
 */
final readonly class AfricasTalkingGateway implements SmsGatewayInterface
{
    public function __construct(
        private string $username,
        private string $apiKey,
        private ?string $senderId = null,
        private string $baseUrl = 'https://api.africastalking.com',
        private int $timeout = 10,
    ) {}

    public function send(string $to, string $message): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->asForm()
                ->withHeaders(['apiKey' => $this->apiKey])
                ->post('/version1/messaging', array_filter([
                    'username' => $this->username,
                    'to' => '+'.ltrim($to, '+'),
                    'message' => $message,
                    'from' => $this->senderId,
                ]));
        } catch (ConnectionException $e) {
            Log::warning("Africa's Talking SMS connection failed.", ['to' => PhoneMask::mask($to), 'error' => $e->getMessage()]);

            return false;
        }

        /** @var list<array{statusCode?: int, status?: string}> $recipients */
        $recipients = (array) $response->json('SMSMessageData.Recipients', []);

        foreach ($recipients as $recipient) {
            if (in_array((int) ($recipient['statusCode'] ?? 0), [100, 101, 102], true)) {
                return true;
            }
        }

        Log::warning("Africa's Talking SMS rejected.", [
            'to' => PhoneMask::mask($to),
            'status' => $response->status(),
            'provider_status' => $recipients[0]['status'] ?? null,
        ]);

        return false;
    }
}
