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
 * Paystack (spec §15.3). Renewals are platform-charged through
 * charge_authorization with the saved authorization code, so the charged
 * amount is always the platform's computed cycle total (§14.5).
 */
final class PaystackGateway extends AbstractGateway
{
    public const string BASE_URL = 'https://api.paystack.co';

    protected function http(): PendingRequest
    {
        return $this->client((string) config('payments.providers.paystack.base_url', self::BASE_URL))
            ->withToken($this->credentials->secretKey)
            ->asJson();
    }

    public function createPlan(PlanPrice $price): string
    {
        $price->loadMissing('plan');

        $response = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http->post('/plan', [
            'name' => $price->plan->name.' ('.$price->billing_interval.', '.$price->currency_code.')',
            'interval' => $price->billing_interval === 'yearly' ? 'annually' : 'monthly',
            'amount' => Money::toMinor((string) $price->amount, $price->currency_code),
            'currency' => $price->currency_code,
        ]), 'createPlan'), 'createPlan');

        return (string) $response->json('data.plan_code');
    }

    public function initiateSubscriptionCharge(Tenant $tenant, PlanPrice $price, Subscription $subscription, ChargeRequest $request): array
    {
        return $this->initiateCharge($request);
    }

    /**
     * Renewals are platform-charged; there is no provider subscription to
     * disable.
     */
    public function cancelSubscription(Subscription $subscription): bool
    {
        return true;
    }

    public function initiateCharge(ChargeRequest $request): array
    {
        $response = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http->post('/transaction/initialize', array_filter([
            'email' => $request->customerEmail,
            'amount' => Money::toMinor($request->amount, $request->currencyCode),
            'currency' => $request->currencyCode,
            'reference' => $request->reference,
            'callback_url' => $request->callbackUrl,
            'metadata' => $request->metadata === [] ? null : $request->metadata,
        ], static fn (mixed $v): bool => $v !== null)), 'initiateCharge'), 'initiateCharge');

        return [
            'checkout_url' => (string) $response->json('data.authorization_url'),
            'reference' => $request->reference,
            'provider_reference' => $response->json('data.access_code'),
        ];
    }

    public function chargeAuthorization(string $authorizationToken, ChargeRequest $request): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/transaction/charge_authorization', [
            'authorization_code' => $authorizationToken,
            'email' => $request->customerEmail,
            'amount' => Money::toMinor($request->amount, $request->currencyCode),
            'currency' => $request->currencyCode,
            'reference' => $request->reference,
            'metadata' => $request->metadata,
        ]), 'chargeAuthorization');

        if ($response->serverError()) {
            return ['reference' => $request->reference, 'provider_reference' => null, 'status' => 'pending', 'failure_reason' => null];
        }

        $status = (string) $response->json('data.status');

        return [
            'reference' => $request->reference,
            'provider_reference' => $response->json('data.id') !== null ? (string) $response->json('data.id') : null,
            'status' => match ($status) {
                'success' => 'successful',
                'failed', 'reversed', 'abandoned' => 'failed',
                default => $response->successful() ? 'pending' : 'failed',
            },
            'failure_reason' => $status === 'success' ? null : ($response->json('data.gateway_response') ?? $this->errorMessage($response)),
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference)), 'verifyTransaction');

        if ($response->status() === 404) {
            return ['status' => 'failed', 'amount' => null, 'currency_code' => null, 'fee' => null, 'authorization_token' => null, 'provider_reference' => null];
        }

        $this->ensureSuccessful($response, 'verifyTransaction');
        $currency = (string) $response->json('data.currency');

        return [
            'status' => match ((string) $response->json('data.status')) {
                'success' => 'successful',
                'failed', 'reversed', 'abandoned' => 'failed',
                default => 'pending',
            },
            'amount' => Money::fromMinor((int) $response->json('data.amount'), $currency),
            'currency_code' => $currency,
            'fee' => $response->json('data.fees') !== null ? Money::fromMinor((int) $response->json('data.fees'), $currency) : null,
            'authorization_token' => $response->json('data.authorization.reusable') ? $response->json('data.authorization.authorization_code') : null,
            'provider_reference' => $response->json('data.id') !== null ? (string) $response->json('data.id') : null,
        ];
    }

    public function refund(string $providerReference, string $amount, string $currencyCode, string $refundReference): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/refund', [
            'transaction' => $providerReference,
            'amount' => Money::toMinor($amount, $currencyCode),
            'merchant_note' => $refundReference,
        ]), 'refund');

        if ($response->serverError()) {
            return ['refund_reference' => null, 'status' => 'pending', 'amount' => $amount];
        }

        return [
            'refund_reference' => $response->json('data.id') !== null ? (string) $response->json('data.id') : null,
            'status' => ! $response->successful() ? 'failed' : match ((string) $response->json('data.status')) {
                'processed' => 'successful',
                'failed' => 'failed',
                default => 'pending',
            },
            'amount' => $amount,
        ];
    }

    /**
     * x-paystack-signature: HMAC-SHA512 of the raw body with the secret key.
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $signature = self::header($headers, 'x-paystack-signature');

        return $signature !== null
            && hash_equals(hash_hmac('sha512', $rawPayload, $this->credentials->secretKey), $signature);
    }

    public function parseWebhookEvent(array $payload, string $rawPayload): WebhookEvent
    {
        $event = (string) ($payload['event'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $transaction = (array) ($data['transaction'] ?? []);
        $currency = (string) ($data['currency'] ?? $transaction['currency'] ?? '');
        $minor = static fn (mixed $value): ?string => $value === null || $currency === '' ? null : Money::fromMinor((int) $value, $currency);

        [$type, $reference, $providerReference, $amount] = match ($event) {
            'charge.success' => [WebhookEvent::CHARGE_SUCCEEDED, $data['reference'] ?? null, $data['id'] ?? null, $minor($data['amount'] ?? null)],
            'refund.processed' => [WebhookEvent::REFUND_PROCESSED, $data['transaction_reference'] ?? null, $data['id'] ?? null, $minor($data['amount'] ?? null)],
            'refund.failed' => [WebhookEvent::REFUND_FAILED, $data['transaction_reference'] ?? null, $data['id'] ?? null, $minor($data['amount'] ?? null)],
            'charge.dispute.create' => [WebhookEvent::DISPUTE_OPENED, $transaction['reference'] ?? null, $data['id'] ?? null, $minor($data['refund_amount'] ?? $transaction['amount'] ?? null)],
            'charge.dispute.resolve' => [
                ($data['resolution'] ?? null) === 'declined' ? WebhookEvent::DISPUTE_WON : WebhookEvent::DISPUTE_LOST,
                $transaction['reference'] ?? null, $data['id'] ?? null, $minor($data['refund_amount'] ?? $transaction['amount'] ?? null),
            ],
            'subscription.disable' => [WebhookEvent::SUBSCRIPTION_CANCELLED, null, $data['subscription_code'] ?? null, null],
            default => [WebhookEvent::IGNORED, $data['reference'] ?? null, $data['id'] ?? null, null],
        };

        $authorization = (array) ($data['authorization'] ?? []);

        return new WebhookEvent(
            eventId: hash('sha256', $rawPayload),
            type: $type,
            reference: $reference !== null ? (string) $reference : null,
            providerReference: $providerReference !== null ? (string) $providerReference : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
            amount: $amount,
            currencyCode: $currency !== '' ? $currency : null,
            fee: $minor($data['fees'] ?? null),
            authorizationToken: ($authorization['reusable'] ?? false) ? ($authorization['authorization_code'] ?? null) : null,
            livemode: isset($data['domain']) ? $data['domain'] === 'live' : null,
            paymentMethodFingerprint: $authorization['signature'] ?? null,
            occurredAt: isset($data['paid_at']) ? CarbonImmutable::parse((string) $data['paid_at']) : CarbonImmutable::now(),
            raw: $payload,
            failureReason: isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
        );
    }

    public function validateCredentials(): array
    {
        $detected = self::modeFromKey($this->credentials->secretKey, 'sk_test_', 'sk_live_');

        try {
            $response = $this->send(fn (PendingRequest $http) => $http->get('/balance'), 'validateCredentials');
        } catch (PaymentGatewayException) {
            return ['valid' => false, 'detected_mode' => $detected, 'message' => 'Paystack did not respond.'];
        }

        return [
            'valid' => $response->successful(),
            'detected_mode' => $detected,
            'message' => $response->successful() ? 'Credentials accepted.' : $this->errorMessage($response),
        ];
    }
}
