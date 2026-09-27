<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use Illuminate\Database\Query\Builder;

/**
 * The tenant staff data-access scope of a metrics request (spec §22.4,
 * §25.3): the warehouses a narrowed staff user may see, or null for all.
 * Orders count when one of their lines is in a visible warehouse; line,
 * stock and shipment figures keep only visible-warehouse rows.
 */
final readonly class MetricsScope
{
    /**
     * @param  list<int>|null  $warehouseIds
     */
    public function __construct(public ?array $warehouseIds = null) {}

    public static function all(): self
    {
        return new self;
    }

    public function isNarrowed(): bool
    {
        return $this->warehouseIds !== null;
    }

    /**
     * Narrows a query to the given warehouse (when visible) or the visible
     * set.
     *
     * @return list<int>|null
     */
    public function warehouses(?int $only = null): ?array
    {
        if ($only === null) {
            return $this->warehouseIds;
        }

        return $this->warehouseIds === null || in_array($only, $this->warehouseIds, true) ? [$only] : [];
    }

    /**
     * Keeps orders with at least one line in a visible warehouse.
     */
    public function orders(Builder $query, string $orderIdColumn = 'o.id'): Builder
    {
        if ($this->warehouseIds === null) {
            return $query;
        }

        $ids = $this->warehouseIds;

        return $query->whereExists(static fn (Builder $q) => $q->from('order_items as scope_items')
            ->whereColumn('scope_items.order_id', $orderIdColumn)
            ->whereIn('scope_items.warehouse_id', $ids === [] ? [0] : $ids));
    }

    /**
     * Keeps rows whose warehouse column is visible.
     */
    public function column(Builder $query, string $column): Builder
    {
        return $this->warehouseIds === null ? $query : $query->whereIn($column, $this->warehouseIds === [] ? [0] : $this->warehouseIds);
    }

    public function cacheKey(): string
    {
        return $this->warehouseIds === null ? 'all' : 'warehouses:'.implode(',', $this->warehouseIds);
    }
}
