<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\ApiException;

/**
 * A stock change would leave an inventory row negative or over-reserved
 * (spec §32.6). Checkout maps it to a stock conflict on the line.
 */
final class InsufficientStockException extends ApiException
{
    public static function for(int $warehouseId, int $productId, ?int $variantId, string $available, string $requested): self
    {
        return new self('insufficient_stock', 'Not enough stock is available for this change.', 422, [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'available' => $available,
            'requested' => $requested,
        ]);
    }
}
