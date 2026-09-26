<?php

declare(strict_types=1);

namespace App\Shared\Payments\DTOs;

/**
 * A one-off or stored-authorization charge (spec §15.2). The reference is
 * ours and doubles as the provider idempotency key.
 */
final readonly class ChargeRequest
{
    /**
     * @param  string  $amount  decimal string in major units
     * @param  array<string, scalar>  $metadata
     */
    public function __construct(
        public string $amount,
        public string $currencyCode,
        public string $reference,
        public string $customerEmail,
        public ?string $customerName = null,
        public ?string $callbackUrl = null,
        public array $metadata = [],
        public bool $saveAuthorization = false,
        public ?string $description = null,
    ) {}
}
