<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\Http\APIResponse;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A business-rule failure with a machine-readable error code (spec §70.8).
 * Rendered by the exception handler through APIResponse; never reported,
 * because it is an expected outcome, not a fault.
 */
class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function unprocessable(string $errorCode, string $message, array $details = []): self
    {
        return new self($errorCode, $message, 422, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function conflict(string $errorCode, string $message, array $details = []): self
    {
        return new self($errorCode, $message, 409, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function forbidden(string $errorCode, string $message, array $details = []): self
    {
        return new self($errorCode, $message, 403, $details);
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self('invalid_transition', "Cannot change status from {$from} to {$to}.", 422, ['from' => $from, 'to' => $to]);
    }

    public function render(): JsonResponse
    {
        return APIResponse::error($this->errorCode, $this->getMessage(), $this->status, $this->details, $this->errors);
    }
}
