<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Warehouse;
use Closure;

/**
 * What references a warehouse and so blocks its deletion (spec §32.9).
 * Inventory registers stock history, transfers and adjustments; orders, POS
 * registers and purchase orders register theirs when they are built.
 */
final class WarehouseUsage
{
    /** @var array<string, Closure(Warehouse): bool> */
    private array $checks = [];

    /**
     * @param  Closure(Warehouse): bool  $inUse
     */
    public function register(string $label, Closure $inUse): void
    {
        $this->checks[$label] = $inUse;
    }

    /**
     * @return list<string> labels of the records that reference the warehouse
     */
    public function usedBy(Warehouse $warehouse): array
    {
        return array_keys(array_filter($this->checks, static fn (Closure $check): bool => $check($warehouse)));
    }
}
