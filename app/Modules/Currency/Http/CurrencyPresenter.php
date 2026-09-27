<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http;

use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ProductPrice;
use App\Modules\Currency\Models\TenantCurrency;
use Illuminate\Support\Collection;

final class CurrencyPresenter
{
    /**
     * @param  Collection<string, ExchangeRate>  $rates  keyed by target currency
     * @return array<string, mixed>
     */
    public function currency(TenantCurrency $currency, Collection $rates, bool $offered): array
    {
        $rate = $rates->get($currency->currency_code);

        return [
            'id' => $currency->id,
            'currency_code' => $currency->currency_code,
            'display_symbol' => $currency->display_symbol,
            'is_base' => $currency->is_base,
            'is_active' => $currency->is_active,
            // 1 base = rate of this currency; null for the base or without a rate.
            'rate' => $currency->is_base || $rate === null ? null : (string) $rate->rate,
            'rate_source' => $rate?->source,
            'rate_updated_at' => $rate?->fetched_at->toIso8601String(),
            // Sold on the storefront now (active, with a rate, module enabled).
            'is_offered' => $offered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function price(ProductPrice $price): array
    {
        return [
            'id' => $price->id,
            'product_id' => $price->product_id,
            'product_variant_id' => $price->product_variant_id,
            'currency_code' => $price->currency_code,
            'price' => (string) $price->price,
            'compare_at_price' => $price->compare_at_price === null ? null : (string) $price->compare_at_price,
            'updated_at' => $price->updated_at?->toIso8601String(),
        ];
    }
}
