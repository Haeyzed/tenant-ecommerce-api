<?php

declare(strict_types=1);

namespace App\Modules\Pos\Terminals;

use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Support\Money;
use Illuminate\Http\Client\PendingRequest;

/**
 * Moniepoint POS push payments (ERP integration, §51.4): an access token
 * from the client credentials, then a PURCHASE pushed to the terminal by
 * serial, polled by our merchantReference. ERP integration must be enabled
 * on the terminal in the Moniepoint dashboard. Moniepoint publishes no
 * refund API: a card payment is reversed on the device.
 * Credentials: client_id, client_secret, terminal_serial.
 */
final class MoniepointTerminalGateway extends AbstractTerminalGateway
{
    public const array CREDENTIALS = ['client_id', 'client_secret', 'terminal_serial'];

    private ?string $token = null;

    public function provider(): string
    {
        return 'moniepoint';
    }

    public function initiateCharge(string $amount, string $currencyCode, string $reference, array $meta = []): array
    {
        // Amounts are in kobo, as in Moniepoint's POS transaction payloads.
        $this->ensureSuccessful($this->send(fn () => $this->http()->post('/v1/transactions', [
            'terminalSerial' => $this->credential('terminal_serial'),
            'amount' => Money::toMinor($amount, $currencyCode),
            'merchantReference' => $reference,
            'transactionType' => 'PURCHASE',
            'paymentMethod' => 'CARD_PURCHASE',
        ]), 'initiateCharge'), 'initiateCharge');

        return ['provider_reference' => null, 'status' => 'pending', 'failure_reason' => null];
    }

    public function verifyCharge(string $reference, ?string $providerReference): array
    {
        $response = $this->send(fn () => $this->http()->get('/v1/transactions/merchants/'.rawurlencode($reference)), 'verifyCharge');

        if ($response->status() === 404) {
            return ['status' => 'pending', 'amount' => null, 'currency_code' => null, 'card_last4' => null, 'provider_reference' => $providerReference, 'failure_reason' => null];
        }

        $data = (array) $this->ensureSuccessful($response, 'verifyCharge')->json();
        $status = strtoupper((string) ($data['transactionStatus'] ?? ''));
        $amount = $data['actualAmount'] ?? $data['requestAmount'] ?? null;

        return [
            'status' => match ($status) {
                'APPROVED', 'SUCCESSFUL', 'SUCCESS', 'COMPLETED' => 'successful',
                'DECLINED', 'FAILED', 'REVERSED', 'CANCELLED', 'CANCELED', 'EXPIRED' => 'failed',
                default => 'pending',
            },
            'amount' => is_numeric($amount) ? Money::fromMinor((int) $amount, 'NGN') : null,
            'currency_code' => is_numeric($amount) ? 'NGN' : null,
            'card_last4' => null,
            'provider_reference' => isset($data['transactionReference']) ? (string) $data['transactionReference'] : $providerReference,
            'failure_reason' => in_array($status, ['DECLINED', 'FAILED'], true) ? strtolower($status) : null,
        ];
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function refund(string $reference, ?string $providerReference, string $amount, string $currencyCode, string $refundReference): array
    {
        throw PaymentGatewayException::rejected($this->provider(), 'refund', 'Moniepoint has no refund API; reverse the payment on the terminal.');
    }

    private function http(): PendingRequest
    {
        return $this->client((string) config('pos.terminals.moniepoint.base_url'))->withToken($this->token());
    }

    /**
     * Held for this driver instance only: access tokens are never stored.
     */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = $this->ensureSuccessful($this->send(fn () => $this->client((string) config('pos.terminals.moniepoint.base_url'))->post('/v1/auth', [
            'clientId' => $this->credential('client_id'),
            'clientSecret' => $this->credential('client_secret'),
        ]), 'authenticate'), 'authenticate');

        return $this->token = (string) $response->json('accessToken');
    }
}
