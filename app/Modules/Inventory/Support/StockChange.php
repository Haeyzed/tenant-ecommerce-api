<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;

/**
 * One change to one inventory row: the unit of InventoryService::apply().
 * Deltas are signed decimal strings.
 */
final readonly class StockChange
{
    public function __construct(
        public Warehouse $warehouse,
        public Product $product,
        public ?ProductVariant $variant,
        public string $quantityDelta,
        public string $reservedDelta,
        public string $movementType,
        public ?string $reason = null,
        public ?string $unitCost = null,
    ) {}

    /**
     * warehouse_id, product_id, variant_key: the lock order (§32.6).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function key(): array
    {
        return [$this->warehouse->id, $this->product->id, $this->variant?->id ?? 0];
    }

    public function keyString(): string
    {
        return implode(':', $this->key());
    }
}
