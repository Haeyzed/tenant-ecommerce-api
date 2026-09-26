<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

use App\Modules\Messaging\Models\PushDeviceToken;
use App\Modules\Messaging\Services\PushDeviceTokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1 API (spec §16.5). The OAuth access
 * token is obtained with a service-account JWT and cached in the landlord
 * store; it is never logged.
 */
final class PushNotificationService
{
    public function __construct(private readonly PushDeviceTokenService $tokens) {}

    public static function isConfigured(): bool
    {
        $config = (array) config('services.fcm');

        return filled($config['project_id'] ?? null)
            && filled($config['client_email'] ?? null)
            && filled($config['private_key'] ?? null);
    }

    /**
     * @param  array<string, scalar>  $data
     */
    public function sendToTenantUser(Model $actor, string $title, string $body, array $data = []): void
    {
        foreach ($this->tokens->tokensFor($actor) as $token) {
            $this->deliver($token, $title, $body, $data);
        }
    }

    public function sendToDevice(string $fcmToken, string $title, string $body): void
    {
        $token = PushDeviceToken::query()->where('token', $fcmToken)->first();

        if ($token !== null) {
            $this->deliver($token, $title, $body, []);
        }
    }

    /**
     * @param  array<string, scalar>  $data
     */
    private function deliver(PushDeviceToken $token, string $title, string $body, array $data): void
    {
        $config = (array) config('services.fcm');

        try {
            $response = Http::baseUrl((string) $config['base_url'])
                ->timeout((int) $config['timeout'])
                ->withToken($this->accessToken())
                ->acceptJson()
                ->post('/v1/projects/'.$config['project_id'].'/messages:send', [
                    'message' => array_filter([
                        'token' => $token->token,
                        'notification' => ['title' => $title, 'body' => $body],
                        // FCM data values must be strings.
                        'data' => $data === [] ? null : array_map(static fn (mixed $v): string => (string) $v, $data),
                    ]),
                ]);
        } catch (ConnectionException $e) {
            // Transient: let the queued notification retry.
            throw new RuntimeException('FCM connection failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            $token->forceFill(['last_used_at' => now()])->save();

            return;
        }

        $status = (string) $response->json('error.status');
        $errorCode = (string) collect((array) $response->json('error.details', []))->pluck('errorCode')->filter()->first();

        // The token is dead: delete it on the spot (§16.5).
        if ($response->status() === 404 || in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true) || $status === 'NOT_FOUND') {
            $token->delete();

            return;
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException('FCM temporarily unavailable (HTTP '.$response->status().').');
        }

        Log::warning('FCM rejected a push message.', ['status' => $response->status(), 'error' => $status ?: $errorCode]);
    }

    private function accessToken(): string
    {
        $config = (array) config('services.fcm');

        return Cache::store('landlord')->remember('fcm:access-token:'.md5((string) $config['client_email']), 3300, function () use ($config): string {
            $now = time();
            $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url((string) json_encode([
                'iss' => $config['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $config['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $key = openssl_pkey_get_private(str_replace('\n', "\n", (string) $config['private_key']));

            if ($key === false || ! openssl_sign($header.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM private key is invalid.');
            }

            $response = Http::asForm()->timeout((int) $config['timeout'])->post((string) $config['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $header.'.'.$claims.'.'.$this->base64Url($signature),
            ]);

            if (! $response->successful() || blank($response->json('access_token'))) {
                throw new RuntimeException('FCM authentication failed (HTTP '.$response->status().').');
            }

            return (string) $response->json('access_token');
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
