<?php

declare(strict_types=1);

namespace App\Modules\Pos\Terminals;

use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Support\Money;
use Illuminate\Http\Client\PendingRequest;

/**
 * Stripe Terminal, server-driven (§51.4): a card_present PaymentIntent is
 * created, then handed to the reader (process_payment_intent); the intent
 * is polled for the outcome. Credentials: secret_key, reader_id.
 */
final class StripeTerminalGateway extends AbstractTerminalGateway
{
    public const array CREDENTIALS = ['secret_key', 'reader_id'];

    public function provider(): string
    {
        return 'stripe_terminal';
    }

    private function http(): PendingRequest
    {
        return $this->client((string) config('pos.terminals.stripe_terminal.base_url'))
            ->withToken($this->credential('secret_key'))
            ->asForm();
    }

    public function initiateCharge(string $amount, string $currencyCode, string $reference, array $meta = []): array
    {
        $intent = $this->ensureSuccessful($this->send(fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => $reference])
            ->post('/v1/payment_intents', [
                'amount' => Money::toMinor($amount, $currencyCode),
                'currency' => strtolower($currencyCode),
                'payment_method_types' => ['card_present'],
                'capture_method' => 'automatic',
                'metadata' => ['reference' => $reference] + $meta,
            ]), 'initiateCharge'), 'initiateCharge');

        $intentId = (string) $intent->json('id');

        $process = $this->send(fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => $reference.'-process'])
            ->post('/v1/terminal/readers/'.rawurlencode($this->credential('reader_id')).'/process_payment_intent', ['payment_intent' => $intentId]), 'initiateCharge');

        if ($process->serverError()) {
            throw PaymentGatewayException::pending($this->provider(), 'initiateCharge');
        }

        return [
            'provider_reference' => $intentId,
            'status' => $process->successful() ? 'pending' : 'failed',
            'failure_reason' => $process->successful() ? null : $this->errorMessage($process),
        ];
    }

    public function verifyCharge(string $reference, ?string $providerReference): array
    {
        if ($providerReference === null) {
            return ['status' => 'failed', 'amount' => null, 'currency_code' => null, 'card_last4' => null, 'provider_reference' => null, 'failure_reason' => 'not_started'];
        }

        $intent = (array) $this->ensureSuccessful($this->send(fn () => $this->http()
            ->get('/v1/payment_intents/'.rawurlencode($providerReference), ['expand' => ['latest_charge']]), 'verifyCharge'), 'verifyCharge')->json();

        $currency = strtoupper((string) ($intent['currency'] ?? ''));
        $declined = ($intent['status'] ?? null) === 'requires_payment_method' && isset($intent['last_payment_error']);

        return [
            'status' => match (true) {
                ($intent['status'] ?? null) === 'succeeded' => 'successful',
                ($intent['status'] ?? null) === 'canceled', $declined => 'failed',
                default => 'pending',
            },
            'amount' => $currency === '' ? null : Money::fromMinor((int) ($intent['amount_received'] ?? 0), $currency),
            'currency_code' => $currency === '' ? null : $currency,
            'card_last4' => isset($intent['latest_charge']['payment_method_details']['card_present']['last4'])
                ? (string) $intent['latest_charge']['payment_method_details']['card_present']['last4'] : null,
            'provider_reference' => $providerReference,
            'failure_reason' => $declined ? (string) ($intent['last_payment_error']['message'] ?? 'declined') : null,
        ];
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(string $reference, ?string $providerReference, string $amount, string $currencyCode, string $refundReference): array
    {
        $response = $this->send(fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => $refundReference])
            ->post('/v1/refunds', [
                'payment_intent' => $providerReference,
                'amount' => Money::toMinor($amount, $currencyCode),
                'metadata' => ['reference' => $refundReference],
            ]), 'refund');

        if ($response->serverError()) {
            return ['status' => 'pending', 'refund_reference' => null];
        }

        return [
            'status' => ! $response->successful() ? 'failed' : match ((string) $response->json('status')) {
                'succeeded' => 'successful',
                'failed', 'canceled' => 'failed',
                default => 'pending',
            },
            'refund_reference' => $response->json('id'),
        ];
    }
}
