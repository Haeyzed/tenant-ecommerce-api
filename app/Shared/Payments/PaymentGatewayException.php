<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use RuntimeException;
use Throwable;

/**
 * A provider call that did not complete normally. "pending" means the
 * outcome is unknown (timeout, 5xx): the provider may still have acted, so
 * callers verify later instead of treating it as a failure.
 */
final class PaymentGatewayException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $operation,
        public readonly bool $pending,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function pending(string $provider, string $operation, ?Throwable $previous = null): self
    {
        return new self("{$provider} {$operation}: no definitive response.", $provider, $operation, true, $previous);
    }

    public static function rejected(string $provider, string $operation, string $reason): self
    {
        return new self("{$provider} {$operation} rejected: {$reason}", $provider, $operation, false);
    }
}
