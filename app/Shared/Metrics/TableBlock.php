<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use JsonSerializable;

/**
 * A top-N table or activity feed (spec §22.2): at most ten rows; the full
 * list is the resource's own list endpoint.
 */
final readonly class TableBlock implements JsonSerializable
{
    public const int MAX_ROWS = 10;

    /**
     * @param  list<array{key: string, label: string, format: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $columns,
        public array $rows,
        public ?string $viewAllRoute = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'columns' => $this->columns,
            'rows' => array_slice($this->rows, 0, self::MAX_ROWS),
            'view_all_route' => $this->viewAllRoute,
        ];
    }
}
