<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Catalog\Services\ProductViewService;
use App\Modules\Catalog\Support\ProductAvailability;
use App\Modules\Catalog\Support\ProductPricing;
use App\Modules\Catalog\Support\ProductSorts;
use App\Modules\Cms\Support\CmsLinkResolver;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Seo\Support\SitemapBuilder;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the catalogue into the shared registries (custom fields §23.1,
 * usage limits §11.10, CMS menu links §24.3, sitemaps §30.2) and holds its
 * own extension points: availability (Inventory, §32), pricing (flash
 * sales §37, warehouse pricing §34, currency §48) and sorts (Orders adds
 * best_selling, §31.2).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProductAvailability::class);
        $this->app->singleton(ProductPricing::class);

        $this->app->singleton(ProductSorts::class, function (): ProductSorts {
            $sorts = new ProductSorts;
            $price = fn (): string => $this->app->make(ProductPricing::class)->priceColumn();

            $sorts->register('newest', static fn (Builder $q) => $q->orderByDesc('products.created_at')->orderByDesc('products.id'));
            $sorts->register('price_asc', static fn (Builder $q) => $q->orderByRaw($price().' asc')->orderBy('products.id'));
            $sorts->register('price_desc', static fn (Builder $q) => $q->orderByRaw($price().' desc')->orderBy('products.id'));
            $sorts->register('top_rated', static fn (Builder $q) => $q->orderByDesc('products.rating_average')->orderByDesc('products.rating_count')->orderBy('products.id'));
            $sorts->register('trending', static fn (Builder $q) => $q
                ->leftJoinSub(ProductViewService::trendingCounts(), 'trend', 'trend.product_id', '=', 'products.id')
                ->orderByRaw('COALESCE(trend.views, 0) desc')
                ->orderByDesc('products.id'));

            return $sorts;
        });

        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register(ProductService::ENTITY, Product::class, 'products');
            $registry->register(ProductService::VARIANT_ENTITY, ProductVariant::class, 'products.variants');
        });

        $this->app->afterResolving(UsageCounterRegistry::class, static function (UsageCounterRegistry $registry): void {
            $registry->register('max_products', static fn (): int => Product::query()->count());
        });

        $this->app->afterResolving(CmsLinkResolver::class, static function (CmsLinkResolver $links): void {
            $path = static fn (string $key, string $slug): string => str_replace('{slug}', $slug, (string) config('cms.paths.'.$key));

            $links->register('product', static fn (int $id): ?string => ($slug = Product::query()->visible()->whereKey($id)->value('slug')) === null ? null : $path('product', $slug));
            $links->register('category', static fn (int $id): ?string => ($slug = Category::query()->where('is_active', true)->whereKey($id)->value('slug')) === null ? null : $path('category', $slug));
            $links->register('brand', static fn (int $id): ?string => ($slug = Brand::query()->whereKey($id)->value('slug')) === null ? null : $path('brand', $slug));
        });

        $this->app->afterResolving(SitemapBuilder::class, static function (SitemapBuilder $builder): void {
            $builder->register('tenant', 'catalog', static function (): iterable {
                foreach (Product::query()->visible()->select(['id', 'slug', 'updated_at'])->orderBy('id')->cursor() as $product) {
                    yield ['path' => str_replace('{slug}', $product->slug, (string) config('cms.paths.product')), 'lastmod' => $product->updated_at];
                }

                foreach (Category::query()->where('is_active', true)->select(['id', 'slug', 'updated_at'])->orderBy('id')->cursor() as $category) {
                    yield ['path' => str_replace('{slug}', $category->slug, (string) config('cms.paths.category')), 'lastmod' => $category->updated_at];
                }

                foreach (Brand::query()->select(['id', 'slug', 'updated_at'])->orderBy('id')->cursor() as $brand) {
                    yield ['path' => str_replace('{slug}', $brand->slug, (string) config('cms.paths.brand')), 'lastmod' => $brand->updated_at];
                }
            });
        });
    }
}
