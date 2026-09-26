<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use JsonSerializable;

/**
 * A chart block (spec §22.2): one or more named series of {x, y} points.
 */
final readonly class ChartSeries implements JsonSerializable
{
    public const string LINE = 'line';

    public const string BAR = 'bar';

    public const string STACKED_BAR = 'stacked_bar';

    public const string DONUT = 'donut';

    /**
     * @param  list<array{key: string, label: string, points: list<array{x: string, y: string|int}>}>  $series
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public string $format,
        public array $series,
        public ?string $interval = null,
        public ?string $currencyCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'format' => $this->format,
            'currency_code' => $this->currencyCode,
            'interval' => $this->interval,
            'series' => $this->series,
        ];
    }
}
