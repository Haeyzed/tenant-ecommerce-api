<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Manufacturing\Models\BillOfMaterial;
use App\Modules\Manufacturing\Models\BillOfMaterialItem;

/**
 * The cost roll-up of a recipe (spec §64.3): Σ quantity_required × the
 * component's current cost price (the variant's, else the product's),
 * divided by yield_quantity. Unknown when any component has no cost; a
 * partial sum would understate the margin, so nothing is written then.
 */
final readonly class ManufacturingCostService
{
    /**
     * @return string|null the unit cost at four places, or null when a component cost is unknown
     */
    public function calculateUnitCost(BillOfMaterial $bom): ?string
    {
        $bom->loadMissing(['items.component', 'items.componentVariant']);
        $total = '0';

        foreach ($bom->items as $item) {
            $cost = self::componentCost($item);

            if ($cost === null) {
                return null;
            }

            $total = bcadd($total, bcmul((string) $item->quantity_required, $cost, 8), 8);
        }

        return bccomp((string) $bom->yield_quantity, '0', 3) <= 0 ? null : bcdiv($total, (string) $bom->yield_quantity, 4);
    }

    /**
     * Writes the roll-up to the finished variant (or product), when known.
     */
    public function applyToProduct(BillOfMaterial $bom): ?string
    {
        $cost = $this->calculateUnitCost($bom);

        if ($cost === null) {
            return null;
        }

        $bom->loadMissing(['product', 'variant']);
        $target = $bom->variant ?? $bom->product;
        $target->forceFill(['cost_price' => $cost])->save();

        return $cost;
    }

    public static function componentCost(BillOfMaterialItem $item): ?string
    {
        $variant = $item->componentVariant;
        $cost = $variant instanceof ProductVariant && $variant->cost_price !== null ? $variant->cost_price : $item->component->cost_price;

        return $cost === null ? null : bcadd((string) $cost, '0', 4);
    }
}
