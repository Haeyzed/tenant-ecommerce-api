<?php

declare(strict_types=1);

namespace App\Shared\Payments\Gateways;

use App\Shared\Payments\Contracts\PaymentGatewayInterface;
use App\Shared\Payments\GatewayCredentials;
use App\Shared\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP handling for the drivers (spec §15.2): 10 s connect and 30 s
 * total timeouts; transport failures surface as PaymentGatewayException
 * with pending = true, never as a failed payment.
 */
abstract class AbstractGateway implements PaymentGatewayInterface
{
    public function __construct(protected readonly GatewayCredentials $credentials) {}

    public function provider(): string
    {
        return $this->credentials->provider;
    }

    public function mode(): string
    {
        return $this->credentials->mode;
    }

    abstract protected function http(): PendingRequest;

    protected function client(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->connectTimeout(10)
            ->timeout(30)
            ->acceptJson();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call, string $operation): Response
    {
        try {
            return $call($this->http());
        } catch (ConnectionException $e) {
            throw PaymentGatewayException::pending($this->provider(), $operation, $e);
        }
    }

    /**
     * Throws for an unsuccessful response, with the provider message only.
     */
    protected function ensureSuccessful(Response $response, string $operation): Response
    {
        if ($response->serverError()) {
            throw PaymentGatewayException::pending($this->provider(), $operation);
        }

        if (! $response->successful()) {
            throw PaymentGatewayException::rejected($this->provider(), $operation, $this->errorMessage($response));
        }

        return $response;
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('error.message') ?? 'HTTP '.$response->status();

        return mb_substr(is_string($message) ? $message : 'HTTP '.$response->status(), 0, 250);
    }

    /**
     * @param  array<string, list<string|null>|string|null>  $headers
     */
    protected static function header(array $headers, string $name): ?string
    {
        $value = $headers[strtolower($name)] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Detected mode from a secret key prefix, or null when unrecognisable.
     */
    protected static function modeFromKey(string $key, string $testPrefix, string $livePrefix): ?string
    {
        return match (true) {
            str_starts_with($key, $testPrefix) => 'test',
            str_starts_with($key, $livePrefix) => 'live',
            default => null,
        };
    }
}
