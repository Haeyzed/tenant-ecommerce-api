<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Shared\Support\Money;
use Illuminate\Support\Collection;

/**
 * Shipping options and cost for an address (spec §36.3). Tracking of
 * shipments arrives with orders.
 */
final readonly class ShippingService
{
    public function __construct(
        private ShippingZoneService $zones,
        private ShippingMethodService $methods,
    ) {}

    /**
     * The resolved zone's active methods, courier and in-house together.
     *
     * @param  array{country_id?: int|null, state_id?: int|null}  $address
     * @return Collection<int, ShippingMethod>
     */
    public function getAvailableMethods(array $address): Collection
    {
        $zone = $this->zones->resolveZoneForAddress($address);

        return $zone === null ? new Collection : $this->methods->listMethodsForZone($zone);
    }

    /**
     * The flat method cost in v1 (base currency); 0 when no line ships.
     *
     * @param  iterable<Product|array{product: Product}>  $lines
     */
    public function calculateShippingCost(ShippingMethod $method, iterable $lines): string
    {
        foreach ($lines as $line) {
            $product = $line instanceof Product ? $line : $line['product'];

            if ($product->isPhysical()) {
                return Money::normalize((string) $method->cost);
            }
        }

        return Money::normalize(0);
    }
}
