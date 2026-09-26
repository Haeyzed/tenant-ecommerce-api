<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

/**
 * The blocks one metric provider contributes to a dashboard section; the
 * dashboard service merges the parts of every provider of the section.
 */
final readonly class SectionResult
{
    /**
     * @param  list<KpiValue>  $kpis
     * @param  list<ChartSeries>  $charts
     * @param  list<TableBlock>  $tables
     * @param  list<Alert>  $alerts
     */
    public function __construct(
        public array $kpis = [],
        public array $charts = [],
        public array $tables = [],
        public array $alerts = [],
    ) {}

    public function merge(self $other): self
    {
        return new self(
            [...$this->kpis, ...$other->kpis],
            [...$this->charts, ...$other->charts],
            [...$this->tables, ...$other->tables],
            [...$this->alerts, ...$other->alerts],
        );
    }

    /**
     * @return array{section: string, range: array<string, string>, comparison_range: array{from: string, to: string}|null, kpis: list<array<string, mixed>>, charts: list<array<string, mixed>>, tables: list<array<string, mixed>>, alerts: list<array<string, mixed>>}
     */
    public function toArray(string $section, DateRange $range): array
    {
        return [
            'section' => $section,
            'range' => $range->toArray(),
            'comparison_range' => $range->comparisonToArray(),
            'kpis' => array_map(static fn (KpiValue $k): array => $k->jsonSerialize(), $this->kpis),
            'charts' => array_map(static fn (ChartSeries $c): array => $c->jsonSerialize(), $this->charts),
            'tables' => array_map(static fn (TableBlock $t): array => $t->jsonSerialize(), $this->tables),
            'alerts' => array_map(static fn (Alert $a): array => $a->jsonSerialize(), $this->alerts),
        ];
    }
}
