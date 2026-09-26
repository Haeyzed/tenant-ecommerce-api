<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Shared\Support\Money;

/**
 * One priced basket line given to the discount engine (spec §37.6). The
 * unit price is already resolved (§38.4) and rounded, in the basket
 * currency.
 */
final readonly class PricingLine
{
    /**
     * @param  string  $priceSource  base | warehouse | currency | flash_sale
     * @param  bool  $discountable  false for gift-card purchases and exchange credit (never eligible)
     */
    public function __construct(
        public int $position,
        public Product $product,
        public ?ProductVariant $variant,
        public string $quantity,
        public string $unitPrice,
        public string $priceSource = 'base',
        public ?int $warehouseId = null,
        public bool $discountable = true,
    ) {}

    public function subtotal(string $currency): string
    {
        return Money::round(bcmul($this->unitPrice, $this->quantity, 10), $currency);
    }
}
