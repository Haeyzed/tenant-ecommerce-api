<?php

declare(strict_types=1);

namespace App\Modules\Pos\Terminals;

use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Support\Money;
use Illuminate\Http\Client\Response;

/**
 * OPay POS (§51.4): the payment is pushed to the terminal identified by its
 * serial number (payMethod "Pos"), then polled through the cashier status
 * API. Every request body is signed: Authorization: Bearer
 * HMAC-SHA512(body, secret key), with the MerchantId header.
 * Credentials: merchant_id, secret_key, terminal_serial.
 */
final class OpayTerminalGateway extends AbstractTerminalGateway
{
    public const array CREDENTIALS = ['merchant_id', 'secret_key', 'terminal_serial'];

    private const string OK = '00000';

    public function provider(): string
    {
        return 'opay';
    }

    public function initiateCharge(string $amount, string $currencyCode, string $reference, array $meta = []): array
    {
        $response = $this->ensureSuccessful($this->post('/api/v1/international/payment/create', [
            'amount' => ['currency' => $currencyCode, 'total' => Money::toMinor($amount, $currencyCode)],
            'country' => $this->country(),
            'payMethod' => 'Pos',
            'product' => ['name' => $meta['description'] ?? 'Sale '.$reference, 'description' => $meta['description'] ?? 'Sale '.$reference],
            'reference' => $reference,
            'sn' => $this->credential('terminal_serial'),
        ], 'initiateCharge'), 'initiateCharge');

        if ($response->json('code') !== self::OK) {
            throw PaymentGatewayException::rejected($this->provider(), 'initiateCharge', (string) ($response->json('message') ?? 'error'));
        }

        return [
            'provider_reference' => $response->json('data.orderNo'),
            'status' => $this->status((string) $response->json('data.status')),
            'failure_reason' => $response->json('data.failureReason'),
        ];
    }

    public function verifyCharge(string $reference, ?string $providerReference): array
    {
        $response = $this->ensureSuccessful($this->post('/api/v1/international/cashier/status', [
            'country' => $this->country(),
            'reference' => $reference,
        ], 'verifyCharge'), 'verifyCharge');

        $currency = $response->json('data.amount.currency');

        return [
            'status' => $response->json('code') === self::OK ? $this->status((string) $response->json('data.status')) : 'pending',
            'amount' => is_string($currency) ? Money::fromMinor((int) $response->json('data.amount.total'), $currency) : null,
            'currency_code' => is_string($currency) ? strtoupper($currency) : null,
            'card_last4' => null,
            'provider_reference' => $response->json('data.orderNo') ?? $providerReference,
            'failure_reason' => $response->json('data.failureReason'),
        ];
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(string $reference, ?string $providerReference, string $amount, string $currencyCode, string $refundReference): array
    {
        $response = $this->post('/api/v1/international/payment/refund/create', [
            'amount' => ['currency' => $currencyCode, 'total' => Money::toMinor($amount, $currencyCode)],
            'country' => $this->country(),
            'originalReference' => $reference,
            'reference' => $refundReference,
        ], 'refund');

        if ($response->serverError()) {
            return ['status' => 'pending', 'refund_reference' => null];
        }

        return [
            'status' => $response->json('code') !== self::OK ? 'failed' : $this->status((string) $response->json('data.orderStatus')),
            'refund_reference' => $response->json('data.orderNo'),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body, string $operation): Response
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);

        return $this->send(fn () => $this->client((string) config('pos.terminals.opay.base_url'))
            ->withToken(hash_hmac('sha512', $json, $this->credential('secret_key')))
            ->withHeaders(['MerchantId' => $this->credential('merchant_id')])
            ->withBody($json, 'application/json')
            ->post($path), $operation);
    }

    private function status(string $status): string
    {
        return match ($status) {
            'SUCCESS' => 'successful',
            'FAIL', 'CLOSE' => 'failed',
            default => 'pending',
        };
    }

    private function country(): string
    {
        return (string) config('pos.terminals.opay.country', 'NG');
    }
}
