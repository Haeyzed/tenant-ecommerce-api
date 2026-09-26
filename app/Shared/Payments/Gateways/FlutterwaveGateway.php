<?php

declare(strict_types=1);

namespace App\Shared\Payments\Gateways;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Payments\DTOs\ChargeRequest;
use App\Shared\Payments\DTOs\WebhookEvent;
use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;

/**
 * Flutterwave v3 (spec §15.3). Amounts are in major units. Renewals are
 * platform-charged through tokenized charges with the saved card token.
 */
final class FlutterwaveGateway extends AbstractGateway
{
    public const string BASE_URL = 'https://api.flutterwave.com/v3';

    protected function http(): PendingRequest
    {
        return $this->client((string) config('payments.providers.flutterwave.base_url', self::BASE_URL))
            ->withToken($this->credentials->secretKey)
            ->asJson();
    }

    public function createPlan(PlanPrice $price): string
    {
        $price->loadMissing('plan');

        $response = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http->post('/payment-plans', [
            'name' => $price->plan->name.' ('.$price->billing_interval.', '.$price->currency_code.')',
            'amount' => (float) Money::round((string) $price->amount, $price->currency_code),
            'interval' => $price->billing_interval === 'yearly' ? 'yearly' : 'monthly',
            'currency' => $price->currency_code,
        ]), 'createPlan'), 'createPlan');

        return (string) $response->json('data.id');
    }

    public function initiateSubscriptionCharge(Tenant $tenant, PlanPrice $price, Subscription $subscription, ChargeRequest $request): array
    {
        return $this->initiateCharge($request);
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        return true;
    }

    public function initiateCharge(ChargeRequest $request): array
    {
        $response = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http->post('/payments', array_filter([
            'tx_ref' => $request->reference,
            'amount' => (float) Money::round($request->amount, $request->currencyCode),
            'currency' => $request->currencyCode,
            'redirect_url' => $request->callbackUrl,
            'customer' => array_filter(['email' => $request->customerEmail, 'name' => $request->customerName]),
            'meta' => $request->metadata === [] ? null : $request->metadata,
            'customizations' => $request->description !== null ? ['description' => $request->description] : null,
        ], static fn (mixed $v): bool => $v !== null)), 'initiateCharge'), 'initiateCharge');

        return [
            'checkout_url' => (string) $response->json('data.link'),
            'reference' => $request->reference,
            'provider_reference' => null,
        ];
    }

    public function chargeAuthorization(string $authorizationToken, ChargeRequest $request): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/tokenized-charges', [
            'token' => $authorizationToken,
            'email' => $request->customerEmail,
            'currency' => $request->currencyCode,
            'amount' => (float) Money::round($request->amount, $request->currencyCode),
            'tx_ref' => $request->reference,
        ]), 'chargeAuthorization');

        if ($response->serverError()) {
            return ['reference' => $request->reference, 'provider_reference' => null, 'status' => 'pending', 'failure_reason' => null];
        }

        $status = (string) $response->json('data.status');

        return [
            'reference' => $request->reference,
            'provider_reference' => $response->json('data.id') !== null ? (string) $response->json('data.id') : null,
            'status' => match ($status) {
                'successful' => 'successful',
                'failed' => 'failed',
                default => $response->successful() ? 'pending' : 'failed',
            },
            'failure_reason' => $status === 'successful' ? null : ($response->json('data.processor_response') ?? $this->errorMessage($response)),
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/transactions/verify_by_reference', ['tx_ref' => $reference]), 'verifyTransaction');

        if ($response->status() === 404 || ($response->status() === 400 && $response->json('status') === 'error')) {
            return ['status' => 'failed', 'amount' => null, 'currency_code' => null, 'fee' => null, 'authorization_token' => null, 'provider_reference' => null];
        }

        $this->ensureSuccessful($response, 'verifyTransaction');

        return [
            'status' => match ((string) $response->json('data.status')) {
                'successful' => 'successful',
                'failed' => 'failed',
                default => 'pending',
            },
            'amount' => $response->json('data.amount') !== null ? Money::normalize((string) $response->json('data.amount')) : null,
            'currency_code' => $response->json('data.currency'),
            'fee' => $response->json('data.app_fee') !== null ? Money::normalize((string) $response->json('data.app_fee')) : null,
            'authorization_token' => $response->json('data.card.token'),
            'provider_reference' => $response->json('data.id') !== null ? (string) $response->json('data.id') : null,
        ];
    }

    public function refund(string $providerReference, string $amount, string $currencyCode, string $refundReference): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/transactions/'.rawurlencode($providerReference).'/refund', [
            'amount' => (float) Money::round($amount, $currencyCode),
            'comments' => $refundReference,
        ]), 'refund');

        if ($response->serverError()) {
            return ['refund_reference' => null, 'status' => 'pending', 'amount' => $amount];
        }

        return [
            'refund_reference' => $response->json('data.id') !== null ? (string) $response->json('data.id') : null,
            'status' => ! $response->successful() ? 'failed' : match ((string) $response->json('data.status')) {
                'completed', 'successful' => 'successful',
                'failed' => 'failed',
                default => 'pending',
            },
            'amount' => $amount,
        ];
    }

    /**
     * verif-hash equals the secret hash configured on the dashboard.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $signature = self::header($headers, 'verif-hash');
        $secret = $this->credentials->webhookSecret;

        return $signature !== null && $secret !== null && $secret !== '' && hash_equals($secret, $signature);
    }

    public function parseWebhookEvent(array $payload, string $rawPayload): WebhookEvent
    {
        $event = (string) ($payload['event'] ?? $payload['event.type'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $status = isset($data['status']) ? (string) $data['status'] : null;

        $type = match (true) {
            $event === 'charge.completed' && $status === 'successful' => WebhookEvent::CHARGE_SUCCEEDED,
            $event === 'charge.completed' && $status === 'failed' => WebhookEvent::CHARGE_FAILED,
            $event === 'refund.completed' && in_array($status, ['completed', 'successful'], true) => WebhookEvent::REFUND_PROCESSED,
            $event === 'refund.completed' => WebhookEvent::REFUND_FAILED,
            default => WebhookEvent::IGNORED,
        };

        $isRefund = str_starts_with($event, 'refund.');

        return new WebhookEvent(
            eventId: hash('sha256', $rawPayload),
            type: $type,
            reference: isset($data['tx_ref']) ? (string) $data['tx_ref'] : null,
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            status: $status,
            amount: isset($data[$isRefund ? 'amount_refunded' : 'amount']) ? Money::normalize((string) $data[$isRefund ? 'amount_refunded' : 'amount']) : (isset($data['amount']) ? Money::normalize((string) $data['amount']) : null),
            currencyCode: isset($data['currency']) ? (string) $data['currency'] : null,
            fee: isset($data['app_fee']) ? Money::normalize((string) $data['app_fee']) : null,
            authorizationToken: isset($data['card']['token']) ? (string) $data['card']['token'] : null,
            livemode: null,
            paymentMethodFingerprint: isset($data['card']['first_6digits'], $data['card']['last_4digits'], $data['card']['expiry'])
                ? hash('sha256', $data['card']['first_6digits'].$data['card']['last_4digits'].$data['card']['expiry'])
                : null,
            occurredAt: isset($data['created_at']) ? CarbonImmutable::parse((string) $data['created_at']) : CarbonImmutable::now(),
            raw: $payload,
            failureReason: isset($data['processor_response']) ? (string) $data['processor_response'] : null,
        );
    }

    public function validateCredentials(): array
    {
        $detected = self::modeFromKey($this->credentials->secretKey, 'FLWSECK_TEST-', 'FLWSECK-');

        try {
            $response = $this->send(fn (PendingRequest $http) => $http->get('/balances'), 'validateCredentials');
        } catch (PaymentGatewayException) {
            return ['valid' => false, 'detected_mode' => $detected, 'message' => 'Flutterwave did not respond.'];
        }

        return [
            'valid' => $response->successful(),
            'detected_mode' => $detected,
            'message' => $response->successful() ? 'Credentials accepted.' : $this->errorMessage($response),
        ];
    }
}
