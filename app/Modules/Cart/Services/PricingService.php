<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Support\PriceResult;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehousePricingService;
use App\Modules\Promotions\Support\FlashSalePrices;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;

/**
 * The only place a unit price is computed (spec §38.4): base price, then
 * the warehouse price, then the currency layer, then a running flash sale
 * when it is lower, then rounding to the currency's minor unit.
 * Promotions, tax and shipping act on the priced basket and never change
 * the unit price.
 */
final readonly class PricingService
{
    public function __construct(
        private WarehousePricingService $warehousePrices,
        private FlashSalePrices $flashSales,
        private TenantSettingsService $settings,
    ) {}

    public function baseCurrency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }

    public function resolveUnitPrice(Product $product, ?ProductVariant $variant, ?Warehouse $warehouse, string $currencyCode): PriceResult
    {
        $currencyCode = strtoupper($currencyCode);

        // Multi-currency (§48) adds the currency layer; until then a basket is
        // always in the base currency.
        if ($currencyCode !== $this->baseCurrency()) {
            throw ApiException::unprocessable('currency_not_supported', 'This store sells in '.$this->baseCurrency().' only.');
        }

        // 1. Base.
        $price = Money::normalize((string) ($variant?->price ?? $product->price));
        $compare = ($variant?->compare_at_price ?? $product->compare_at_price) === null ? null : Money::normalize((string) ($variant?->compare_at_price ?? $product->compare_at_price));
        $source = PriceResult::BASE;

        // 2. Warehouse.
        if ($warehouse !== null && ($row = $this->warehousePrices->getPriceForWarehouse($product, $warehouse, $variant)) !== null) {
            $price = $row['price'];
            $compare = $row['compare_at_price'];
            $source = PriceResult::WAREHOUSE;
        }

        // 4. Flash sale, only when lower; the regular price becomes the
        // struck-through price.
        $sale = $this->flashSales->priceFor($product->id);

        if ($sale !== null && Money::cmp($sale, $price) < 0) {
            $compare = $compare === null ? $price : Money::max($compare, $price);
            $price = $sale;
            $source = PriceResult::FLASH_SALE;
        }

        // 5. Rounding.
        return new PriceResult(
            Money::round($price, $currencyCode),
            $compare === null ? null : Money::round($compare, $currencyCode),
            $currencyCode,
            $source,
        );
    }
}
