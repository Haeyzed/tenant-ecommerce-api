<?php

declare(strict_types=1);

namespace App\Modules\Promotions;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\ProductPricing;
use App\Modules\Catalog\Support\ProductPromotions;
use App\Modules\Customers\Models\Customer;
use App\Modules\Exports\Support\ExportDefinition;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Promotions\Models\Coupon;
use App\Modules\Promotions\Services\PromotionEngine;
use App\Modules\Promotions\Support\BuyerHistory;
use App\Modules\Promotions\Support\FlashSalePrices;
use App\Modules\Promotions\Support\PromotionCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Wires promotions into the catalogue: flash sales are a unit-price layer
 * (§38.4 step 4) for display, sorting and filtering, and automatic line
 * promotions preview on storefront cards (§37.6). Coupon codes export
 * through the asynchronous export mechanism (§37.9).
 */
final class PromotionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromotionCache::class);
        $this->app->singleton(FlashSalePrices::class);
        $this->app->singleton(BuyerHistory::class);

        $this->app->afterResolving(ProductPricing::class, function (ProductPricing $pricing): void {
            $prices = fn (): FlashSalePrices => $this->app->make(FlashSalePrices::class);

            // A sale never raises a price: ProductPricing keeps the lower one.
            $pricing->registerAdjuster('flash_sale', static fn (Product $product, ?ProductVariant $variant, string $current): string => $prices()->priceFor($product->id) ?? $current);
            $pricing->usePriceExpression(static fn (): string => $prices()->priceExpression());
        });

        $this->app->afterResolving(ProductPromotions::class, function (ProductPromotions $promotions): void {
            $promotions->useResolver(function (Collection $products): array {
                $customer = Auth::guard('customer')->user();

                return $this->app->make(PromotionEngine::class)->previewForProducts($products, $customer instanceof Customer ? $customer : null);
            });
        });

        $this->app->afterResolving(ExportRegistry::class, static function (ExportRegistry $registry): void {
            $registry->register(new ExportDefinition(
                type: 'coupons',
                label: 'Coupon codes',
                rules: ['promotion_id' => ['required', 'integer']],
                columns: ['code' => 'Code', 'usage_limit' => 'Usage limit', 'times_redeemed' => 'Times redeemed', 'expires_at' => 'Expires at', 'is_active' => 'Active'],
                rows: static fn (array $parameters): iterable => Coupon::query()->where('promotion_id', (int) $parameters['promotion_id'])->orderBy('id')->lazy(1000)
                    ->map(static fn (Coupon $c): array => [
                        'code' => $c->code,
                        'usage_limit' => $c->usage_limit,
                        'times_redeemed' => $c->times_redeemed,
                        'expires_at' => $c->expires_at?->toIso8601String(),
                        'is_active' => $c->is_active ? 'yes' : 'no',
                    ]),
                permission: 'promotions.coupons.view',
            ));
        });
    }
}
