<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\DigitalProductFile;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBundleItem;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\ProductQuestion;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductBadgeService;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Catalog\Support\ProductAvailability;
use App\Modules\Catalog\Support\ProductPricing;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Settings\Services\TenantSettingsService;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * JSON shapes of catalogue records. Storefront shapes expose the effective
 * price and in_stock, never costs, codes of inactive items, moderation
 * fields or warehouse quantities (§31.1).
 */
final readonly class CatalogPresenter
{
    public function __construct(
        private ProductPricing $pricing,
        private ProductAvailability $availability,
        private ProductBadgeService $badges,
        private CustomFieldService $customFields,
        private TenantSettingsService $settings,
    ) {}

    /**
     * Storefront cards for a list page, with availability resolved in one
     * pass.
     *
     * @param  iterable<Product>  $products
     * @return list<array<string, mixed>>
     */
    public function storefrontCards(iterable $products): array
    {
        $products = new Collection(is_array($products) ? $products : iterator_to_array($products));
        $stock = $this->availability->forProducts($products);

        return $products->map(fn (Product $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'product_type' => $p->product_type,
            'price' => $this->pricing->effectivePrice($p),
            'compare_at_price' => $this->pricing->compareAtPrice($p),
            'in_stock' => $stock[$p->id] ?? false,
            'brand' => $p->brand === null ? null : ['id' => $p->brand->id, 'name' => $p->brand->name, 'slug' => $p->brand->slug],
            'image_url' => $this->imageUrl($p),
            'rating_average' => (string) $p->rating_average,
            'rating_count' => $p->rating_count,
            'badges' => $this->badges->getBadgesForProduct($p)->pluck('type')->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function storefrontProduct(Product $product): array
    {
        $product->loadMissing(['brand', 'unit', 'categories', 'tags', 'specifications', 'badges', 'media',
            'variants' => static fn ($q) => $q->where('is_active', true)->with(['optionValues.option', 'media']),
            'bundleItems.child', 'bundleItems.childVariant']);

        $variants = $product->variants;

        return [
            ...$this->storefrontCards([$product])[0],
            'description' => $product->description,
            'unit' => $product->unit === null ? ['name' => 'Piece', 'short_code' => 'pc', 'allows_decimal' => false]
                : ['name' => $product->unit->name, 'short_code' => $product->unit->short_code, 'allows_decimal' => $product->unit->allows_decimal],
            'view_count' => (bool) $this->settings->get('product_view_count_public', true) ? $product->view_count : null,
            'gallery' => $product->getMedia('gallery')->map(static fn (Media $m): array => ['id' => $m->id, 'url' => $m->getUrl()])->values()->all(),
            'categories' => $product->categories->map(static fn (Category $c): array => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'is_primary' => (bool) $c->pivot->is_primary])->values()->all(),
            'tags' => $product->tags->map(static fn ($t): array => ['name' => $t->name, 'slug' => $t->slug])->values()->all(),
            'specifications' => $product->specifications->map(static fn ($s): array => $s->only(['spec_group', 'spec_key', 'spec_value']))->values()->all(),
            'options' => $this->optionsOf($variants),
            'variants' => $variants->map(fn (ProductVariant $v): array => [
                'id' => $v->id,
                'sku' => $v->sku,
                'price' => $this->pricing->effectivePrice($product, $v),
                'compare_at_price' => $this->pricing->compareAtPrice($product, $v),
                'option_value_ids' => $v->optionValues->pluck('id')->values()->all(),
                'image_url' => $v->getFirstMediaUrl('image') ?: null,
            ])->values()->all(),
            'bundle_items' => $product->bundleItems->map(static fn (ProductBundleItem $i): array => [
                'product' => ['id' => $i->child->id, 'name' => $i->child->name, 'slug' => $i->child->slug],
                'variant_id' => $i->child_product_variant_id,
                'quantity' => (string) $i->quantity,
            ])->values()->all(),
            'seo' => $this->seo($product),
            'custom_fields' => $this->customFields->valuesFor($product, ProductService::ENTITY, CustomFieldService::PUBLIC),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminProduct(Product $product, bool $detail): array
    {
        $base = [
            'id' => $product->id,
            'product_type' => $product->product_type,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (string) $product->price,
            'compare_at_price' => $product->compare_at_price !== null ? (string) $product->compare_at_price : null,
            'cost_price' => $product->cost_price !== null ? (string) $product->cost_price : null,
            'tax_class' => $product->tax_class,
            'is_active' => $product->is_active,
            'moderation_status' => $product->moderation_status,
            'brand' => $product->brand === null ? null : ['id' => $product->brand->id, 'name' => $product->brand->name],
            'categories' => $product->categories->map(static fn (Category $c): array => ['id' => $c->id, 'name' => $c->name, 'is_primary' => (bool) $c->pivot->is_primary])->values()->all(),
            'image_url' => $this->imageUrl($product),
            'view_count' => $product->view_count,
            'created_at' => $product->created_at->toIso8601String(),
        ];

        if (! $detail) {
            return $base;
        }

        return [
            ...$base,
            'description' => $product->description,
            'unit_id' => $product->unit_id,
            'hsn_code' => $product->hsn_code,
            'expiry_date' => $product->expiry_date?->toDateString(),
            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'meta_keywords' => $product->meta_keywords,
            'tags' => $product->tags->map(static fn ($t): array => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            'specifications' => $product->specifications->map(static fn ($s): array => $s->only(['id', 'spec_group', 'spec_key', 'spec_value', 'sort_order']))->values()->all(),
            'badges' => $product->badges->map(static fn ($b): array => $b->only(['id', 'badge_type', 'label']) + ['starts_at' => $b->starts_at?->toIso8601String(), 'ends_at' => $b->ends_at?->toIso8601String()])->values()->all(),
            'variants' => $product->variants->map(fn (ProductVariant $v): array => $this->adminVariant($v))->values()->all(),
            'bundle_items' => $product->bundleItems->map(static fn (ProductBundleItem $i): array => [
                'id' => $i->id, 'child_product_id' => $i->child_product_id, 'child_name' => $i->child?->name,
                'child_product_variant_id' => $i->child_product_variant_id, 'quantity' => (string) $i->quantity,
            ])->values()->all(),
            'digital_files' => $product->digitalFiles->map(static fn (DigitalProductFile $f): array => [
                'id' => $f->id, 'name' => $f->getFirstMedia('file')?->name, 'size' => $f->getFirstMedia('file')?->size,
                'download_limit' => $f->download_limit, 'expires_after_days' => $f->expires_after_days,
            ])->values()->all(),
            'media' => $product->media->map(static fn (Media $m): array => ['id' => $m->id, 'collection' => $m->collection_name, 'url' => $m->getUrl(), 'order' => $m->order_column])->values()->all(),
            'custom_fields' => $this->customFields->valuesFor($product, ProductService::ENTITY, CustomFieldService::ADMIN),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminVariant(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => $variant->price !== null ? (string) $variant->price : null,
            'compare_at_price' => $variant->compare_at_price !== null ? (string) $variant->compare_at_price : null,
            'cost_price' => $variant->cost_price !== null ? (string) $variant->cost_price : null,
            'is_active' => $variant->is_active,
            'options' => $variant->optionValues->map(static fn (ProductOptionValue $v): array => ['option' => $v->option?->name, 'value_id' => $v->id, 'value' => $v->value])->values()->all(),
            'custom_fields' => $this->customFields->valuesFor($variant, ProductService::VARIANT_ENTITY, CustomFieldService::ADMIN),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function category(Category $category, bool $public): array
    {
        return array_filter([
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'sort_order' => $category->sort_order,
            'is_active' => $public ? null : $category->is_active,
            'image_url' => $category->getFirstMediaUrl('image') ?: null,
            'seo' => $this->seo($category),
        ], static fn ($v, string $k): bool => $v !== null || in_array($k, ['parent_id', 'description', 'image_url'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  list<array{category: Category, children: list<mixed>}>  $tree
     * @return list<array<string, mixed>>
     */
    public function categoryTree(array $tree, bool $public): array
    {
        return array_map(fn (array $node): array => [...$this->category($node['category'], $public), 'children' => $this->categoryTree($node['children'], $public)], $tree);
    }

    /**
     * @return array<string, mixed>
     */
    public function brand(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'description' => $brand->description,
            'logo_url' => $brand->getFirstMediaUrl('logo') ?: null,
            'seo' => $this->seo($brand),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function option(ProductOption $option): array
    {
        return [
            'id' => $option->id,
            'name' => $option->name,
            'sort_order' => $option->sort_order,
            'values' => $option->values->map(static fn (ProductOptionValue $v): array => ['id' => $v->id, 'value' => $v->value, 'sort_order' => $v->sort_order])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function question(ProductQuestion $question, bool $public): array
    {
        return array_filter([
            'id' => $question->id,
            'product' => $public ? null : ['id' => $question->product_id, 'name' => $question->product?->name],
            'asked_by' => $question->customer?->anonymized_at === null ? $question->customer?->name : 'Deleted customer',
            'question' => $question->question,
            'is_approved' => $public ? null : $question->is_approved,
            'asked_at' => $question->asked_at->toIso8601String(),
            'answers' => $question->answers->map(static fn ($a): array => array_filter([
                'id' => $a->id,
                'answer' => $a->answer,
                'answered_by' => $a->answered_by_type === 'seller' ? 'seller' : 'store',
                'is_approved' => $public ? null : $a->is_approved,
                'created_at' => $a->created_at?->toIso8601String(),
            ], static fn ($v): bool => $v !== null))->values()->all(),
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * SEO meta with fallbacks to the name and description (§30.2).
     *
     * @return array{meta_title: string, meta_description: string|null, meta_keywords: string|null, og_image_url: string|null}
     */
    public function seo(Product|Category|Brand $model): array
    {
        $description = $model->meta_description ?? ($model->description !== null ? mb_strimwidth(strip_tags((string) $model->description), 0, 320, '…') : null);

        return [
            'meta_title' => $model->meta_title ?? $model->name,
            'meta_description' => $description,
            'meta_keywords' => $model->meta_keywords,
            'og_image_url' => $model->getFirstMediaUrl('og_image') ?: null,
        ];
    }

    private function imageUrl(Product $product): ?string
    {
        return ($product->getFirstMediaUrl('featured') ?: $product->getFirstMediaUrl('gallery')) ?: null;
    }

    /**
     * The options of the active variants and their used values.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array{id: int, name: string, values: list<array{id: int, value: string}>}>
     */
    private function optionsOf(Collection $variants): array
    {
        $options = [];

        foreach ($variants as $variant) {
            foreach ($variant->optionValues as $value) {
                $options[$value->product_option_id] ??= ['id' => $value->product_option_id, 'name' => (string) $value->option?->name, 'values' => []];
                $options[$value->product_option_id]['values'][$value->id] = ['id' => $value->id, 'value' => $value->value];
            }
        }

        return array_values(array_map(static fn (array $o): array => [...$o, 'values' => array_values($o['values'])], $options));
    }
}
