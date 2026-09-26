<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use JsonSerializable;

/**
 * A condition needing action now (spec §22.2).
 */
final readonly class Alert implements JsonSerializable
{
    public const string INFO = 'info';

    public const string WARNING = 'warning';

    public const string CRITICAL = 'critical';

    public function __construct(
        public string $key,
        public string $severity,
        public string $message,
        public int $count,
        public ?string $route = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'message' => $this->message,
            'count' => $this->count,
            'route' => $this->route,
        ];
    }
}
