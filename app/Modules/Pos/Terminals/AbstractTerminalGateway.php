<?php

declare(strict_types=1);

namespace App\Modules\Pos\Terminals;

use App\Shared\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP handling for the terminal drivers, as for the online
 * gateways (§15.2): 10 s connect and 30 s total timeouts; a transport
 * failure is an unknown outcome, never a failed payment.
 */
abstract class AbstractTerminalGateway implements PosTerminalGatewayInterface
{
    /**
     * @param  array<string, string>  $credentials  the register's terminal_credentials
     */
    public function __construct(protected readonly array $credentials) {}

    protected function client(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)->connectTimeout(10)->timeout(30)->acceptJson();
    }

    /**
     * @param  callable(): Response  $call
     */
    protected function send(callable $call, string $operation): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw PaymentGatewayException::pending($this->provider(), $operation, $e);
        }
    }

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

    protected function credential(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }
}
