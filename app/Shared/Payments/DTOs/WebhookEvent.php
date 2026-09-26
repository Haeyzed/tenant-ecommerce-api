<?php

declare(strict_types=1);

namespace App\Shared\Payments\DTOs;

use Carbon\CarbonImmutable;

/**
 * A provider webhook normalised by its driver (spec §15.2).
 */
final readonly class WebhookEvent
{
    public const string CHARGE_SUCCEEDED = 'charge.succeeded';

    public const string CHARGE_FAILED = 'charge.failed';

    public const string SUBSCRIPTION_CANCELLED = 'subscription.cancelled';

    public const string REFUND_PROCESSED = 'refund.processed';

    public const string REFUND_FAILED = 'refund.failed';

    public const string DISPUTE_OPENED = 'dispute.opened';

    public const string DISPUTE_WON = 'dispute.won';

    public const string DISPUTE_LOST = 'dispute.lost';

    /** Events the platform does not act on. */
    public const string IGNORED = 'ignored';

    /**
     * @param  string|null  $reference  our own reference of the charge concerned
     * @param  string|null  $providerReference  the provider id of the object the event is about (charge, refund or dispute)
     * @param  string|null  $refundReference  our own reference of a refund, where the provider echoes it
     * @param  string|null  $chargeProviderReference  the provider id of the original charge, for refunds and disputes
     * @param  string|null  $amount  decimal string, major units, always positive
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $reference,
        public ?string $providerReference,
        public ?string $status,
        public ?string $amount,
        public ?string $currencyCode,
        public ?string $fee = null,
        public ?string $authorizationToken = null,
        public ?bool $livemode = null,
        public ?string $paymentMethodFingerprint = null,
        public ?CarbonImmutable $occurredAt = null,
        public array $raw = [],
        public ?string $refundReference = null,
        public ?string $failureReason = null,
        public ?string $chargeProviderReference = null,
    ) {}
}
