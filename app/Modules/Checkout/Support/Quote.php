<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Support;

use App\Modules\Cart\Support\PriceResult;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotions\Support\PromotionResult;
use App\Modules\Shipping\Models\ShippingMethod;

/**
 * A priced basket (spec §38.5, §38.6 steps 1–8). Lines that cannot be
 * bought (unavailable, out of stock) are reported but not totalled.
 * hash identifies exactly what the customer was shown (the price guard).
 */
final readonly class Quote
{
    public const string OK = 'ok';

    public const string UNAVAILABLE = 'unavailable';

    public const string OUT_OF_STOCK = 'out_of_stock';

    /**
     * @param  list<array{item_id: int|null, product: Product, variant: ProductVariant|null, quantity: string, status: string, warehouse: Warehouse|null, price: PriceResult|null, line_subtotal: string, discount_amount: string, seller_funded_discount_amount: string, tax_rate_applied: string|null, tax_amount: string|null, tax_breakdown: array<string, string>|null, line_total: string|null}>  $lines
     * @param  array{country_id: int|null, state_id: int|null, address_id: int|null}|null  $address
     * @param  array<string, string|null>  $totals
     * @param  list<array{code: string, message: string}>  $issues
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public ?array $address,
        public bool $requiresShipping,
        public ?ShippingMethod $shippingMethod,
        public PromotionResult $promotions,
        public ?string $couponCode,
        public bool $pricesIncludeTax,
        public array $totals,
        public array $issues,
        public string $hash,
    ) {}

    public function isComplete(): bool
    {
        return $this->issues === [] && $this->totals['tax_amount'] !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency_code' => $this->currency,
            'prices_include_tax' => $this->pricesIncludeTax,
            'lines' => array_map(static fn (array $l): array => [
                'item_id' => $l['item_id'],
                'product' => ['id' => $l['product']->id, 'name' => $l['product']->name, 'slug' => $l['product']->slug, 'product_type' => $l['product']->product_type],
                'variant' => $l['variant'] === null ? null : ['id' => $l['variant']->id, 'sku' => $l['variant']->sku],
                'quantity' => $l['quantity'],
                'status' => $l['status'],
                'unit_price' => $l['price']?->unitPrice,
                'compare_at_price' => $l['price']?->compareAtPrice,
                'price_source' => $l['price']?->source,
                'line_subtotal' => $l['line_subtotal'],
                'discount_amount' => $l['discount_amount'],
                'tax_rate_applied' => $l['tax_rate_applied'],
                'tax_amount' => $l['tax_amount'],
                'line_total' => $l['line_total'],
            ], $this->lines),
            'promotions' => array_map(static fn (array $p): array => [
                'promotion_id' => $p['promotion_id'], 'label' => $p['label'], 'scope' => $p['scope'], 'amount' => $p['amount'],
            ], $this->promotions->applied),
            'coupon' => $this->couponCode === null ? null : [
                'code' => $this->couponCode,
                'applied' => $this->promotions->couponApplied(),
                'reason' => $this->promotions->couponRejectionReason,
            ],
            'requires_shipping' => $this->requiresShipping,
            'shipping_method' => $this->shippingMethod === null ? null : ['id' => $this->shippingMethod->id, 'name' => $this->shippingMethod->name],
            ...$this->totals,
            'issues' => $this->issues,
            'quote_hash' => $this->hash,
        ];
    }
}
