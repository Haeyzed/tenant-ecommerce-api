<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

/**
 * What a report returns (spec §61.2): an optional summary, the columns,
 * and its rows, either a query (paginated on screen, streamed on export;
 * it must be fully ordered) with a row mapper, or a fixed list. A chart
 * report also carries its points.
 */
final readonly class ReportResult
{
    /**
     * @param  list<array{key: string, label: string, format: string}>  $columns
     * @param  array<string, mixed>|null  $summary
     * @param  (Closure(object): array<string, mixed>)|null  $map
     * @param  list<array<string, mixed>>|null  $rows
     * @param  array<string, mixed>|null  $chart
     * @param  list<string>  $notes
     * @param  (Closure(int, int): LengthAwarePaginator<int, object>)|null  $pages  a report served by another service's paginated query
     */
    public function __construct(
        public array $columns,
        public ?array $summary = null,
        public ?Builder $query = null,
        public ?Closure $map = null,
        public ?array $rows = null,
        public ?array $chart = null,
        public array $notes = [],
        public ?Closure $pages = null,
    ) {}

    /**
     * Every row, in chunks, for exports.
     *
     * @return iterable<array<string, mixed>>
     */
    public function stream(): iterable
    {
        if ($this->pages !== null) {
            $page = 1;

            do {
                $chunk = ($this->pages)($page++, 500);

                foreach ($chunk->items() as $row) {
                    yield $this->map === null ? (array) $row : ($this->map)($row);
                }
            } while ($chunk->hasMorePages());

            return;
        }

        if ($this->query === null) {
            yield from $this->rows ?? ($this->summary === null ? [] : [$this->summary]);

            return;
        }

        foreach ($this->query->lazy(1000) as $row) {
            yield $this->map === null ? (array) $row : ($this->map)($row);
        }
    }
}
