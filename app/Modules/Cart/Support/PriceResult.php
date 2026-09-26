<?php

declare(strict_types=1);

namespace App\Modules\Cart\Support;

/**
 * A resolved unit price (spec §38.4). source is base, warehouse, currency
 * or flash_sale; is_estimated marks a converted (not explicit) currency
 * price.
 */
final readonly class PriceResult
{
    public const string BASE = 'base';

    public const string WAREHOUSE = 'warehouse';

    public const string CURRENCY = 'currency';

    public const string FLASH_SALE = 'flash_sale';

    public function __construct(
        public string $unitPrice,
        public ?string $compareAtPrice,
        public string $currencyCode,
        public string $source,
        public bool $isEstimated = false,
    ) {}
}
