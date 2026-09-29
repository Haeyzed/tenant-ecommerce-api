<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Carbon\CarbonInterface;

/**
 * An order placed on another platform, already matched to our products,
 * ready to import (spec §68.3, §69.2). Amounts are in $currency and
 * exclusive of tax, as WooCommerce and the channels report them.
 */
final readonly class ExternalOrder
{
    /**
     * @param  list<array{product: Product, variant: ProductVariant|null, quantity: string, unit_price: string, discount_amount: string, tax_amount: string}>  $lines
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function __construct(
        public string $source,
        public string $idempotencyKey,
        public string $currency,
        public ?string $customerEmail,
        public ?string $customerName,
        public ?string $customerPhone,
        public ?array $shippingAddress,
        public array $lines,
        public string $shippingAmount,
        public string $shippingTax,
        public string $total,
        public bool $paid,
        public string $paidNote,
        public ?CarbonInterface $placedAt,
    ) {}
}
