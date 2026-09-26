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
 * Stripe (spec §15.3): Checkout Sessions for first payments, off-session
 * PaymentIntents with the saved customer and payment method for renewals.
 * Every money-moving call sends our reference as Idempotency-Key.
 */
final class StripeGateway extends AbstractGateway
{
    public const string BASE_URL = 'https://api.stripe.com';

    private const int SIGNATURE_TOLERANCE = 300;

    protected function http(): PendingRequest
    {
        return $this->client((string) config('payments.providers.stripe.base_url', self::BASE_URL))
            ->withToken($this->credentials->secretKey)
            ->asForm();
    }

    public function createPlan(PlanPrice $price): string
    {
        $price->loadMissing('plan');

        $product = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => 'plan-product-'.$price->plan_id.'-'.$this->mode()])
            ->post('/v1/products', ['name' => $price->plan->name]), 'createPlan'), 'createPlan');

        $response = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => 'plan-price-'.$price->id.'-'.$this->mode()])
            ->post('/v1/prices', [
                'product' => $product->json('id'),
                'unit_amount' => Money::toMinor((string) $price->amount, $price->currency_code),
                'currency' => strtolower($price->currency_code),
                'recurring' => ['interval' => $price->billing_interval === 'yearly' ? 'year' : 'month'],
            ]), 'createPlan'), 'createPlan');

        return (string) $response->json('id');
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
        $paymentIntentData = ['metadata' => ['reference' => $request->reference] + $request->metadata];

        if ($request->saveAuthorization) {
            $paymentIntentData['setup_future_usage'] = 'off_session';
        }

        $response = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $request->reference])
            ->post('/v1/checkout/sessions', array_filter([
                'mode' => 'payment',
                'success_url' => $request->callbackUrl,
                'cancel_url' => $request->callbackUrl,
                'client_reference_id' => $request->reference,
                'customer_email' => $request->customerEmail,
                'customer_creation' => 'always',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($request->currencyCode),
                        'unit_amount' => Money::toMinor($request->amount, $request->currencyCode),
                        'product_data' => ['name' => $request->description ?? 'Payment '.$request->reference],
                    ],
                ]],
                'metadata' => ['reference' => $request->reference],
                'payment_intent_data' => $paymentIntentData,
            ], static fn (mixed $v): bool => $v !== null)), 'initiateCharge'), 'initiateCharge');

        return [
            'checkout_url' => (string) $response->json('url'),
            'reference' => $request->reference,
            'provider_reference' => $response->json('id'),
        ];
    }

    public function chargeAuthorization(string $authorizationToken, ChargeRequest $request): array
    {
        [$customer, $paymentMethod] = array_pad(explode('|', $authorizationToken, 2), 2, null);

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $request->reference])
            ->post('/v1/payment_intents', [
                'amount' => Money::toMinor($request->amount, $request->currencyCode),
                'currency' => strtolower($request->currencyCode),
                'customer' => $customer,
                'payment_method' => $paymentMethod,
                'off_session' => 'true',
                'confirm' => 'true',
                'metadata' => ['reference' => $request->reference] + $request->metadata,
            ]), 'chargeAuthorization');

        if ($response->serverError()) {
            return ['reference' => $request->reference, 'provider_reference' => null, 'status' => 'pending', 'failure_reason' => null];
        }

        $intent = $response->successful() ? (array) $response->json() : (array) $response->json('error.payment_intent', []);
        $status = (string) ($intent['status'] ?? '');

        return [
            'reference' => $request->reference,
            'provider_reference' => isset($intent['id']) ? (string) $intent['id'] : null,
            'status' => match ($status) {
                'succeeded' => 'successful',
                'processing' => 'pending',
                default => 'failed',
            },
            'failure_reason' => $status === 'succeeded' ? null : ($response->json('error.message') ?? $status),
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        $response = $this->ensureSuccessful($this->send(fn (PendingRequest $http) => $http->get('/v1/payment_intents/search', [
            'query' => "metadata['reference']:'".str_replace("'", '', $reference)."'",
            'limit' => 1,
        ]), 'verifyTransaction'), 'verifyTransaction');

        $intent = (array) $response->json('data.0', []);

        // The search index lags a little: absence is not a failure.
        if ($intent === []) {
            return ['status' => 'pending', 'amount' => null, 'currency_code' => null, 'fee' => null, 'authorization_token' => null, 'provider_reference' => null];
        }

        $currency = strtoupper((string) $intent['currency']);

        return [
            'status' => match ((string) $intent['status']) {
                'succeeded' => 'successful',
                'canceled' => 'failed',
                default => 'pending',
            },
            'amount' => Money::fromMinor((int) ($intent['amount_received'] ?? 0), $currency),
            'currency_code' => $currency,
            'fee' => null,
            'authorization_token' => self::authorizationToken($intent),
            'provider_reference' => (string) $intent['id'],
        ];
    }

    public function refund(string $providerReference, string $amount, string $currencyCode, string $refundReference): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $refundReference])
            ->post('/v1/refunds', [
                'payment_intent' => $providerReference,
                'amount' => Money::toMinor($amount, $currencyCode),
                'metadata' => ['reference' => $refundReference],
            ]), 'refund');

        if ($response->serverError()) {
            return ['refund_reference' => null, 'status' => 'pending', 'amount' => $amount];
        }

        return [
            'refund_reference' => $response->json('id'),
            'status' => ! $response->successful() ? 'failed' : match ((string) $response->json('status')) {
                'succeeded' => 'successful',
                'failed', 'canceled' => 'failed',
                default => 'pending',
            },
            'amount' => $amount,
        ];
    }

    /**
     * Stripe-Signature: t=timestamp,v1=HMAC-SHA256("t.payload"); events
     * older than five minutes are rejected (spec §15.6 step 2).
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool
    {
        $header = self::header($headers, 'stripe-signature');
        $secret = $this->credentials->webhookSecret;

        if ($header === null || $secret === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(now()->getTimestamp() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawPayload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhookEvent(array $payload, string $rawPayload): WebhookEvent
    {
        $eventType = (string) ($payload['type'] ?? '');
        $object = (array) ($payload['data']['object'] ?? []);
        $currency = isset($object['currency']) ? strtoupper((string) $object['currency']) : null;
        $minor = static fn (mixed $v): ?string => $v === null || $currency === null ? null : Money::fromMinor((int) $v, $currency);
        $metadata = (array) ($object['metadata'] ?? []);

        $type = match (true) {
            $eventType === 'payment_intent.succeeded' => WebhookEvent::CHARGE_SUCCEEDED,
            $eventType === 'payment_intent.payment_failed' => WebhookEvent::CHARGE_FAILED,
            in_array($eventType, ['refund.updated', 'charge.refund.updated'], true) && ($object['status'] ?? null) === 'succeeded' => WebhookEvent::REFUND_PROCESSED,
            in_array($eventType, ['refund.updated', 'charge.refund.updated'], true) && in_array($object['status'] ?? null, ['failed', 'canceled'], true) => WebhookEvent::REFUND_FAILED,
            $eventType === 'charge.dispute.created' => WebhookEvent::DISPUTE_OPENED,
            $eventType === 'charge.dispute.closed' && ($object['status'] ?? null) === 'won' => WebhookEvent::DISPUTE_WON,
            $eventType === 'charge.dispute.closed' && ($object['status'] ?? null) === 'lost' => WebhookEvent::DISPUTE_LOST,
            default => WebhookEvent::IGNORED,
        };

        $isIntent = str_starts_with($eventType, 'payment_intent.');
        $isRefund = in_array($type, [WebhookEvent::REFUND_PROCESSED, WebhookEvent::REFUND_FAILED], true);

        return new WebhookEvent(
            eventId: (string) ($payload['id'] ?? hash('sha256', $rawPayload)),
            type: $type,
            reference: $isIntent && isset($metadata['reference']) ? (string) $metadata['reference'] : null,
            providerReference: isset($object['id']) ? (string) $object['id'] : null,
            status: isset($object['status']) ? (string) $object['status'] : null,
            amount: $minor($isIntent ? ($object['amount_received'] ?? $object['amount'] ?? null) : ($object['amount'] ?? null)),
            currencyCode: $currency,
            authorizationToken: $isIntent ? self::authorizationToken($object) : null,
            livemode: isset($payload['livemode']) ? (bool) $payload['livemode'] : null,
            occurredAt: isset($payload['created']) ? CarbonImmutable::createFromTimestamp((int) $payload['created']) : CarbonImmutable::now(),
            raw: $payload,
            refundReference: $isRefund && isset($metadata['reference']) ? (string) $metadata['reference'] : null,
            failureReason: isset($object['last_payment_error']['message']) ? (string) $object['last_payment_error']['message'] : null,
            chargeProviderReference: ! $isIntent && isset($object['payment_intent']) ? (string) $object['payment_intent'] : null,
        );
    }

    public function validateCredentials(): array
    {
        $key = $this->credentials->secretKey;
        $detected = self::modeFromKey($key, 'sk_test_', 'sk_live_') ?? self::modeFromKey($key, 'rk_test_', 'rk_live_');

        try {
            $response = $this->send(fn (PendingRequest $http) => $http->get('/v1/balance'), 'validateCredentials');
        } catch (PaymentGatewayException) {
            return ['valid' => false, 'detected_mode' => $detected, 'message' => 'Stripe did not respond.'];
        }

        if ($response->successful() && is_bool($response->json('livemode'))) {
            $detected = $response->json('livemode') ? 'live' : 'test';
        }

        return [
            'valid' => $response->successful(),
            'detected_mode' => $detected,
            'message' => $response->successful() ? 'Credentials accepted.' : $this->errorMessage($response),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private static function authorizationToken(array $intent): ?string
    {
        $customer = $intent['customer'] ?? null;
        $method = $intent['payment_method'] ?? null;

        if (is_array($method)) {
            $method = $method['id'] ?? null;
        }

        return is_string($customer) && is_string($method) ? $customer.'|'.$method : null;
    }
}
