<?php

declare(strict_types=1);

namespace App\Shared\Payments;

/**
 * Decrypted credentials of one provider and mode. Built only by
 * PaymentGatewayFactory from stored rows; never from request input.
 */
final readonly class GatewayCredentials
{
    /**
     * @param  array<string, string>  $extra
     */
    public function __construct(
        public string $provider,
        public string $mode,
        #[\SensitiveParameter] public string $secretKey,
        public ?string $publicKey = null,
        #[\SensitiveParameter] public ?string $webhookSecret = null,
        #[\SensitiveParameter] public array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'mode' => $this->mode];
    }
}
