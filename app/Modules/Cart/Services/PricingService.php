<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Support\PriceResult;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehousePricingService;
use App\Modules\Promotions\Support\FlashSalePrices;
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
        private CurrencyService $currencies,
    ) {}

    public function baseCurrency(): string
    {
        return $this->currencies->baseCurrency();
    }

    /**
     * @param  string|null  $rate  the basket's rate (1 base = rate), when the caller already resolved it
     */
    public function resolveUnitPrice(Product $product, ?ProductVariant $variant, ?Warehouse $warehouse, string $currencyCode, ?string $rate = null): PriceResult
    {
        $currencyCode = strtoupper($currencyCode);
        $rate ??= $currencyCode === $this->baseCurrency() ? '1' : $this->currencies->offeredRate($currencyCode);
        $converted = $currencyCode !== $this->baseCurrency();
        $estimated = false;

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

        // 3. Currency: the store's explicit market price replaces 1–2 (a
        // variant row, then the product row); otherwise 1–2 is converted at
        // the reference rate and marked estimated.
        if ($converted) {
            $explicit = $this->currencies->explicitPrice($product->id, $variant?->id, $currencyCode);

            if ($explicit !== null) {
                $price = $explicit['price'];
                $compare = $explicit['compare_at_price'];
                $source = PriceResult::CURRENCY;
            } else {
                $price = bcmul($price, $rate, CurrencyService::SCALE);
                $compare = $compare === null ? null : bcmul($compare, $rate, CurrencyService::SCALE);
                $estimated = true;
            }
        }

        // 4. Flash sale (base currency, converted), only when lower; the
        // regular price becomes the struck-through price.
        $sale = $this->flashSales->priceFor($product->id);
        $sale = $sale === null || ! $converted ? $sale : bcmul($sale, $rate, CurrencyService::SCALE);

        if ($sale !== null && Money::cmp($sale, $price) < 0) {
            $compare = $compare === null ? $price : Money::max($compare, $price);
            $price = $sale;
            $source = PriceResult::FLASH_SALE;
            $estimated = $estimated || $converted;
        }

        // 5. Rounding to the currency's minor unit.
        return new PriceResult(
            Money::round($price, $currencyCode),
            $compare === null ? null : Money::round($compare, $currencyCode),
            $currencyCode,
            $source,
            $estimated,
        );
    }
}
