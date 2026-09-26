<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A product (or variant) went from no available stock to some (spec §32.7),
 * raised after the stock change commits. Back-in-stock alerts (§56) listen.
 */
final readonly class StockReplenished
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public int $productId,
        public ?int $variantId,
    ) {}
}
