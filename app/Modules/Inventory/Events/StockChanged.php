<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stock of these products moved (spec §32, the integration hook of
 * §68.3), raised once per committed stock change. Integrations that mirror
 * stock elsewhere listen; the core never depends on them.
 */
final readonly class StockChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $productIds
     */
    public function __construct(
        public string $tenantId,
        public array $productIds,
    ) {}
}
