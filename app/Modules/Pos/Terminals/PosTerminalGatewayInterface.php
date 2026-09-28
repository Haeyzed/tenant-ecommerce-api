<?php

declare(strict_types=1);

namespace App\Modules\Pos\Terminals;

/**
 * A card terminal (spec §51.4): a device transaction pushed to the
 * terminal and polled, not a redirect-and-webhook flow. Amounts are decimal
 * strings in major units, like every other money value (§5.3). Transport
 * failures throw PaymentGatewayException (pending = unknown outcome).
 */
interface PosTerminalGatewayInterface
{
    public function provider(): string;

    /**
     * Pushes the charge to the terminal; the customer then taps or inserts.
     *
     * @param  array<string, string>  $meta
     * @return array{provider_reference: string|null, status: string, failure_reason: string|null} status: pending | successful | failed
     */
    public function initiateCharge(string $amount, string $currencyCode, string $reference, array $meta = []): array;

    /**
     * @return array{status: string, amount: string|null, currency_code: string|null, card_last4: string|null, provider_reference: string|null, failure_reason: string|null}
     */
    public function verifyCharge(string $reference, ?string $providerReference): array;

    /**
     * Whether the provider can reverse a charge through its API. Without it,
     * the cashier reverses the payment on the device.
     */
    public function supportsRefunds(): bool;

    /**
     * @return array{status: string, refund_reference: string|null} status: pending | successful | failed
     */
    public function refund(string $reference, ?string $providerReference, string $amount, string $currencyCode, string $refundReference): array;
}
