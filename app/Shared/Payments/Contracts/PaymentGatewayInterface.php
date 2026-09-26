<?php

declare(strict_types=1);

namespace App\Shared\Payments\Contracts;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Payments\DTOs\ChargeRequest;
use App\Shared\Payments\DTOs\WebhookEvent;

/**
 * One payment provider, built for exactly one mode (spec §15.2). Drivers
 * never call application services. Money-moving calls send our own
 * reference as the provider idempotency key; a timeout reports "pending".
 */
interface PaymentGatewayInterface
{
    public function provider(): string;

    public function mode(): string;

    /**
     * Provider-side plan for a price (landlord billing).
     */
    public function createPlan(PlanPrice $price): string;

    /**
     * First charge of a subscription; saves a reusable authorization for
     * platform-charged renewals.
     *
     * @return array{checkout_url: string, reference: string, provider_reference: string|null}
     */
    public function initiateSubscriptionCharge(Tenant $tenant, PlanPrice $price, Subscription $subscription, ChargeRequest $request): array;

    public function cancelSubscription(Subscription $subscription): bool;

    /**
     * @return array{checkout_url: string, reference: string, provider_reference: string|null}
     */
    public function initiateCharge(ChargeRequest $request): array;

    /**
     * @return array{reference: string, provider_reference: string|null, status: string, failure_reason: string|null}
     */
    public function chargeAuthorization(string $authorizationToken, ChargeRequest $request): array;

    /**
     * @return array{status: string, amount: string|null, currency_code: string|null, fee: string|null, authorization_token: string|null, provider_reference: string|null}
     */
    public function verifyTransaction(string $reference): array;

    /**
     * @return array{refund_reference: string|null, status: string, amount: string}
     */
    public function refund(string $providerReference, string $amount, string $currencyCode, string $refundReference): array;

    /**
     * @param  array<string, list<string|null>|string|null>  $headers  lower-cased names
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhookEvent(array $payload, string $rawPayload): WebhookEvent;

    /**
     * @return array{valid: bool, detected_mode: string|null, message: string}
     */
    public function validateCredentials(): array;
}
