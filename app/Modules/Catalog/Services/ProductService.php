<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\DigitalProductFile;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBundleItem;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Support\ProductAvailability;
use App\Modules\Catalog\Support\ProductPricing;
use App\Modules\Catalog\Support\ProductSorts;
use App\Modules\Cms\Support\SitemapTrigger;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Products and their type-specific parts (spec §27.3, §27.4, §28.5, §30.1,
 * §31.2). max_products is enforced on the create and duplicate routes by
 * usage.limit (under the per-tenant limit lock).
 */
final readonly class ProductService
{
    public const string ENTITY = 'product';

    public const string VARIANT_ENTITY = 'product_variant';

    private const array MEDIA_COLLECTIONS = ['gallery', 'featured', 'og_image'];

    public function __construct(
        private CategoryService $categories,
        private CatalogReferenceService $references,
        private CustomFieldService $customFields,
        private StorageQuota $quota,
        private ProductAvailability $availability,
        private ProductPricing $pricing,
        private ProductSorts $sorts,
    ) {}

    /**
     * Admin listing, including inactive products.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function listProducts(array $filters): LengthAwarePaginator
    {
        $query = Product::query()->with(['brand:id,name', 'categories:id,name', 'media']);

        if (filled($filters['search'] ?? null)) {
            $this->applySearch($query, (string) $filters['search']);
        }

        return $query
            ->when($filters['product_type'] ?? null, static fn ($q, $v) => $q->where('product_type', $v))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($filters['brand_id'] ?? null, static fn ($q, $v) => $q->where('brand_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $this->categories->withDescendants([(int) $v]))))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function getProduct(Product $product): Product
    {
        return $product->load(['brand', 'unit', 'categories', 'tags', 'variants.optionValues.option', 'variants.media', 'bundleItems.child', 'bundleItems.childVariant', 'digitalFiles.media', 'specifications', 'badges', 'media']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createProduct(array $data): Product
    {
        [$validated, $custom] = $this->validateProduct($data, null);

        $product = DB::connection('tenant')->transaction(function () use ($validated, $custom): Product {
            $this->assertCodesFree($validated['sku'] ?? null, $validated['barcode'] ?? null, null, null);

            $product = new Product(Arr::except($validated, ['category_ids', 'primary_category_id', 'tag_ids', 'bundle_items', 'custom_fields']));
            $product->save();

            $this->syncRelations($product, $validated);

            foreach ((array) ($validated['bundle_items'] ?? []) as $item) {
                $this->addBundleItem($product, Product::query()->findOrFail((int) $item['child_product_id']), (string) $item['quantity'],
                    isset($item['child_product_variant_id']) ? ProductVariant::query()->findOrFail((int) $item['child_product_variant_id']) : null);
            }

            $this->customFields->save($product, self::ENTITY, $custom);

            return $product;
        });

        if ($product->is_active) {
            SitemapTrigger::requested();
        }

        return $this->getProduct($product);
    }

    /**
     * product_type is fixed at creation: variants, bundle lines and files
     * depend on it.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(Product $product, array $data): Product
    {
        if (array_key_exists('product_type', $data) && $data['product_type'] !== $product->product_type) {
            throw ValidationException::withMessages(['product_type' => ['The product type cannot change. Create a new product instead.']]);
        }

        [$validated, $custom] = $this->validateProduct($data, $product);
        $wasActive = $product->is_active;

        DB::connection('tenant')->transaction(function () use ($product, $validated, $custom): void {
            $this->assertCodesFree($validated['sku'] ?? null, $validated['barcode'] ?? null, $product->id, null);
            $product->fill(Arr::except($validated, ['category_ids', 'primary_category_id', 'tag_ids', 'bundle_items', 'custom_fields', 'product_type']))->save();
            $this->syncRelations($product, $validated);
            $this->customFields->save($product, self::ENTITY, $custom);
        });

        if ($wasActive || $product->is_active) {
            SitemapTrigger::requested();
        }

        return $this->getProduct($product->refresh());
    }

    public function deleteProduct(Product $product): void
    {
        $product->delete();
        SitemapTrigger::requested();
    }

    /**
     * A copy with a new slug and suffixed codes, plus categories, tags,
     * specifications, variants and bundle lines (never inventory or
     * media). The copy starts inactive so it is reviewed before selling.
     */
    public function duplicateProduct(Product $product): Product
    {
        $product->load(['categories', 'tags', 'specifications', 'variants.optionValues', 'bundleItems']);

        $copy = DB::connection('tenant')->transaction(function () use ($product): Product {
            $copy = $product->replicate(['slug', 'sku', 'barcode', 'view_count', 'rating_average', 'rating_count']);
            $copy->name = mb_substr($product->name.' (copy)', 0, 255);
            $copy->sku = $product->sku === null ? null : $this->freeCode($product->sku.'-COPY');
            $copy->is_active = false;
            $copy->save();

            $primary = $product->categories->firstWhere('pivot.is_primary', true)?->id;
            $this->categories->attachCategories($copy, $product->categories->pluck('id')->all(), $primary);
            $copy->tags()->sync($product->tags->pluck('id')->all());

            foreach ($product->specifications as $spec) {
                $copy->specifications()->create($spec->only(['spec_group', 'spec_key', 'spec_value', 'sort_order']));
            }

            foreach ($product->variants as $variant) {
                $newVariant = $variant->replicate(['sku', 'barcode']);
                $newVariant->product_id = $copy->id;
                $newVariant->sku = $this->freeCode($variant->sku.'-COPY');
                $newVariant->save();
                $newVariant->optionValues()->sync($variant->optionValues->pluck('id')->all());
            }

            foreach ($product->bundleItems as $item) {
                $copy->bundleItems()->create($item->only(['child_product_id', 'child_product_variant_id', 'quantity']));
            }

            return $copy;
        });

        return $this->getProduct($copy);
    }

    /**
     * §70.12: each item runs through the same service method in its own
     * transaction; one bad item never rolls back the others.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, status: string, error: string|null, message: string|null}>
     */
    public function bulk(string $action, array $ids): array
    {
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $product = Product::query()->findOrFail($id);
                $this->updateProduct($product, ['is_active' => $action === 'activate']);
                $results[] = ['id' => $id, 'status' => 'ok', 'error' => null, 'message' => null];
            } catch (Throwable $e) {
                $results[] = [
                    'id' => $id,
                    'status' => 'error',
                    'error' => $e instanceof ApiException ? $e->errorCode : ($e instanceof ValidationException ? 'validation_failed' : 'not_found'),
                    'message' => $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    // ---- Variants (§28.2) ----------------------------------------------

    /**
     * @param  list<int>  $optionValueIds
     * @param  array<string, mixed>  $data
     */
    public function createVariant(Product $product, array $optionValueIds, array $data): ProductVariant
    {
        if ($product->product_type !== Product::VARIABLE) {
            throw ApiException::unprocessable('product_not_variable', 'Only variable products have variants.');
        }

        [$validated, $custom] = $this->validateVariant($data, true);

        return DB::connection('tenant')->transaction(function () use ($product, $optionValueIds, $validated, $custom): ProductVariant {
            // Serialise variant writes of one product (cap and combinations).
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            if (ProductVariant::query()->where('product_id', $product->id)->count() >= ProductVariant::MAX_PER_PRODUCT) {
                throw ApiException::unprocessable('variant_limit_reached', 'A product has at most '.ProductVariant::MAX_PER_PRODUCT.' variants.');
            }

            $values = $this->assertCombination($product, $optionValueIds, null);
            $this->assertCodesFree($validated['sku'], $validated['barcode'] ?? null, null, null);

            $variant = new ProductVariant($validated);
            $variant->product_id = $product->id;
            $variant->save();
            $variant->optionValues()->sync($values);
            $this->customFields->save($variant, self::VARIANT_ENTITY, $custom);

            return $variant->load('optionValues.option');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        [$validated, $custom] = $this->validateVariant($data, false, $variant);

        DB::connection('tenant')->transaction(function () use ($variant, $validated, $custom, $data): void {
            $this->assertCodesFree($validated['sku'] ?? null, $validated['barcode'] ?? null, null, $variant->id);

            if (isset($data['option_value_ids'])) {
                $variant->optionValues()->sync($this->assertCombination($variant->product, (array) $data['option_value_ids'], $variant->id));
            }

            $variant->fill($validated)->save();
            $this->customFields->save($variant, self::VARIANT_ENTITY, $custom);
        });

        return $variant->load('optionValues.option');
    }

    public function deleteVariant(ProductVariant $variant): void
    {
        $variant->delete();
    }

    // ---- Bundles (§28.3) ---------------------------------------------

    public function addBundleItem(Product $bundle, Product $child, string $quantity, ?ProductVariant $childVariant = null): ProductBundleItem
    {
        if ($bundle->product_type !== Product::BUNDLE) {
            throw ApiException::unprocessable('product_not_bundle', 'Only bundles have bundle items.');
        }

        if ($child->product_type === Product::BUNDLE || $child->id === $bundle->id) {
            throw ApiException::unprocessable('bundle_child_invalid', 'A bundle cannot contain a bundle.');
        }

        if ($child->product_type === Product::VARIABLE && $childVariant === null) {
            throw ValidationException::withMessages(['child_product_variant_id' => ['Choose the variant of a variable product.']]);
        }

        if ($childVariant !== null && $childVariant->product_id !== $child->id) {
            throw ValidationException::withMessages(['child_product_variant_id' => ['The variant does not belong to the child product.']]);
        }

        if (! is_numeric($quantity) || Money::cmp(Money::normalize($quantity), '0') <= 0) {
            throw ValidationException::withMessages(['quantity' => ['The quantity must be greater than zero.']]);
        }

        if (ProductBundleItem::query()->where('bundle_product_id', $bundle->id)->where('child_product_id', $child->id)
            ->where('child_product_variant_id', $childVariant?->id)->exists()) {
            throw ApiException::conflict('bundle_item_exists', 'This item is already in the bundle.');
        }

        /** @var ProductBundleItem $item */
        $item = ProductBundleItem::query()->create([
            'bundle_product_id' => $bundle->id,
            'child_product_id' => $child->id,
            'child_product_variant_id' => $childVariant?->id,
            'quantity' => bcadd($quantity, '0', 3),
        ]);

        return $item;
    }

    public function removeBundleItem(ProductBundleItem $item): void
    {
        $item->delete();
    }

    // ---- Digital files (§28.4) ---------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    public function attachDigitalFile(Product $product, UploadedFile $file, array $data = []): DigitalProductFile
    {
        if ($product->product_type !== Product::DIGITAL) {
            throw ApiException::unprocessable('product_not_digital', 'Only digital products have downloadable files.');
        }

        $validated = validator(['file' => $file, ...$data], [
            'file' => ['required', 'file', 'max:512000', 'extensions:pdf,zip,epub,mp3,mp4,m4a,wav,jpg,jpeg,png,webp,txt,csv,docx,xlsx,pptx'],
            'download_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'expires_after_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ])->validate();

        $this->quota->assertAllows($file);

        return DB::connection('tenant')->transaction(static function () use ($product, $file, $validated): DigitalProductFile {
            /** @var DigitalProductFile $record */
            $record = $product->digitalFiles()->create([
                'download_limit' => $validated['download_limit'] ?? null,
                'expires_after_days' => $validated['expires_after_days'] ?? null,
            ]);

            $record->addMedia($file)
                ->usingFileName(Str::uuid().'.'.$file->getClientOriginalExtension())
                ->usingName(mb_substr($file->getClientOriginalName(), 0, 200))
                ->toMediaCollection('file');

            return $record->load('media');
        });
    }

    public function removeDigitalFile(DigitalProductFile $file): void
    {
        $file->delete();
    }

    // ---- Media (§30.1) -----------------------------------------------

    public function attachMedia(Product $product, UploadedFile $file, string $collection = 'gallery'): Media
    {
        if (! in_array($collection, self::MEDIA_COLLECTIONS, true)) {
            throw ValidationException::withMessages(['collection' => ['Unknown image collection.']]);
        }

        Validator::make(['image' => $file], ['image' => ['required', ...UploadRules::image()]])->validate();
        $this->quota->assertAllows($file);

        return $product->addMedia($file)
            ->usingFileName(Str::uuid().'.'.$file->guessExtension())
            ->usingName(mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 200))
            ->toMediaCollection($collection);
    }

    public function removeMedia(Product $product, int $mediaId): void
    {
        $product->media()->whereKey($mediaId)->firstOrFail()->delete();
    }

    /**
     * @param  list<int>  $orderedMediaIds
     */
    public function reorderMedia(Product $product, array $orderedMediaIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $orderedMediaIds)));

        if ($product->media()->where('collection_name', 'gallery')->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['ordered_ids' => ['Every id must be a gallery image of this product.']]);
        }

        Media::setNewOrder($ids);
    }

    /**
     * Copies a gallery image into the single "featured" collection.
     */
    public function setFeaturedImage(Product $product, int $mediaId): Media
    {
        /** @var Media $media */
        $media = $product->media()->where('collection_name', 'gallery')->whereKey($mediaId)->firstOrFail();

        return $media->copy($product, 'featured');
    }

    // ---- Storefront query (§31.2) ------------------------------------

    /**
     * The single query builder behind every storefront product listing.
     * Each filter is its own conditional scope.
     *
     * @param  array<string, mixed>  $filters  validated
     * @param  array<string, mixed>  $baseConstraints  e.g. ['category_id' => 5] for a category listing
     * @return LengthAwarePaginator<int, Product>
     */
    public function searchAndFilter(array $filters, ?string $sort, int $perPage, array $baseConstraints = []): LengthAwarePaginator
    {
        $filters = [...$filters, ...$baseConstraints];
        $query = Product::query()->visible()->select('products.*')->with(['brand:id,name,slug', 'media', 'badges']);

        if (filled($filters['search'] ?? null)) {
            $this->applySearch($query, (string) $filters['search']);
        }

        $query
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->whereExists(fn ($s) => $s->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', $this->categories->withDescendants($this->ids($v)))))
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->whereIn('products.brand_id', $this->ids($v)))
            ->when($filters['tag'] ?? null, static fn ($q, $v) => $q->whereExists(static fn ($s) => $s->from('product_tag')
                ->join('tags', 'tags.id', '=', 'product_tag.tag_id')
                ->whereColumn('product_tag.product_id', 'products.id')
                ->whereIn('tags.slug', array_filter(explode(',', (string) $v)))))
            ->when(isset($filters['min_price']) || isset($filters['max_price']), fn ($q) => $this->pricing->applyPriceRange(
                $q, isset($filters['min_price']) ? (string) $filters['min_price'] : null, isset($filters['max_price']) ? (string) $filters['max_price'] : null))
            ->when($filters['option'] ?? null, fn ($q, $v) => $this->applyOptionFilter($q, (array) $v))
            ->when(($filters['in_stock'] ?? null) !== null && (bool) $filters['in_stock'], fn ($q) => $this->availability->applyInStockFilter($q))
            ->when($filters['badge'] ?? null, fn ($q, $v) => $this->applyBadgeFilter($q, (string) $v));

        $this->sorts->apply($query, $sort ?? 'newest');

        return $query->paginate($perPage);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        $query->where(function (Builder $q) use ($like, $term): void {
            $q->where('products.name', 'like', $like)
                ->orWhere('products.description', 'like', $like)
                ->orWhere('products.sku', trim($term))
                ->orWhere('products.barcode', trim($term))
                ->orWhereExists(static fn ($s) => $s->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->whereNull('product_variants.deleted_at')
                    ->where(static fn ($v) => $v->where('product_variants.sku', trim($term))->orWhere('product_variants.barcode', trim($term))));

            $this->customFields->applySearch($q, self::ENTITY, $term);
        });
    }

    /**
     * option[Size]=L&option[Color]=Red: products with at least one active
     * variant matching every given option value.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, string>  $options
     */
    private function applyOptionFilter(Builder $query, array $options): void
    {
        $query->whereExists(static function ($variants) use ($options): void {
            $variants->from('product_variants')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('product_variants.is_active', true)
                ->whereNull('product_variants.deleted_at');

            foreach ($options as $name => $value) {
                $variants->whereExists(static fn ($s) => $s->from('product_variant_option_values as pvov')
                    ->join('product_option_values as pov', 'pov.id', '=', 'pvov.product_option_value_id')
                    ->join('product_options as po', 'po.id', '=', 'pov.product_option_id')
                    ->whereColumn('pvov.product_variant_id', 'product_variants.id')
                    ->where('po.name', (string) $name)
                    ->where('pov.value', (string) $value));
            }
        });
    }

    /**
     * Manual badges in their window, plus the computed "new" and
     * "on_sale" (catalogue level: price below compare-at, §29.5).
     *
     * @param  Builder<Product>  $query
     */
    private function applyBadgeFilter(Builder $query, string $badge): void
    {
        $query->where(function (Builder $q) use ($badge): void {
            $q->whereExists(static fn ($s) => $s->from('product_badges')
                ->whereColumn('product_badges.product_id', 'products.id')
                ->where('product_badges.badge_type', $badge)
                ->where(static fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now())));

            if ($badge === 'new') {
                $q->orWhere('products.created_at', '>=', now()->subDays(ProductBadgeService::NEW_DAYS));
            }

            if ($badge === 'on_sale') {
                $q->orWhereRaw($this->pricing->priceColumn().' < products.compare_at_price');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncRelations(Product $product, array $validated): void
    {
        if (array_key_exists('category_ids', $validated)) {
            $this->categories->attachCategories($product, (array) $validated['category_ids'], isset($validated['primary_category_id']) ? (int) $validated['primary_category_id'] : null);
        }

        if (array_key_exists('tag_ids', $validated)) {
            $this->references->syncProductTags($product, (array) $validated['tag_ids']);
        }
    }

    /**
     * SKU and barcode are unique across products AND variants (§28.2);
     * checked inside the writing transaction.
     */
    private function assertCodesFree(?string $sku, ?string $barcode, ?int $productId, ?int $variantId): void
    {
        foreach (['sku' => $sku, 'barcode' => $barcode] as $field => $code) {
            if ($code === null || $code === '') {
                continue;
            }

            $taken = Product::withTrashed()->where(static fn ($q) => $q->where('sku', $code)->orWhere('barcode', $code))
                ->when($productId !== null, static fn ($q) => $q->whereKeyNot($productId))->exists()
                || ProductVariant::withTrashed()->where(static fn ($q) => $q->where('sku', $code)->orWhere('barcode', $code))
                    ->when($variantId !== null, static fn ($q) => $q->whereKeyNot($variantId))->exists();

            if ($taken) {
                throw ValidationException::withMessages([$field => ["The code {$code} is already used by another product or variant."]]);
            }
        }
    }

    /**
     * One value per option, and no two variants with the same set.
     *
     * @param  list<int|string>  $optionValueIds
     * @return list<int>
     */
    private function assertCombination(Product $product, array $optionValueIds, ?int $ignoreVariantId): array
    {
        $ids = array_values(array_unique(array_map('intval', $optionValueIds)));
        $values = ProductOptionValue::query()->whereKey($ids)->get();

        if ($ids === [] || $values->count() !== count($ids)) {
            throw ValidationException::withMessages(['option_value_ids' => ['Choose existing option values.']]);
        }

        if ($values->pluck('product_option_id')->unique()->count() !== $values->count()) {
            throw ValidationException::withMessages(['option_value_ids' => ['A variant has at most one value per option.']]);
        }

        sort($ids);
        $existing = ProductVariant::query()->where('product_id', $product->id)
            ->when($ignoreVariantId !== null, static fn ($q) => $q->whereKeyNot($ignoreVariantId))
            ->with('optionValues:id')->get();

        foreach ($existing as $variant) {
            $set = $variant->optionValues->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

            if ($set === $ids) {
                throw ApiException::conflict('variant_combination_exists', 'A variant with these option values already exists.', ['variant_id' => $variant->id]);
            }
        }

        return $ids;
    }

    private function freeCode(string $base): string
    {
        $code = mb_substr($base, 0, 60);
        $n = 2;

        while (Product::withTrashed()->where('sku', $code)->orWhere('barcode', $code)->exists()
            || ProductVariant::withTrashed()->where('sku', $code)->orWhere('barcode', $code)->exists()) {
            $code = mb_substr($base, 0, 56).'-'.$n++;
        }

        return $code;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        return array_values(array_filter(array_map('intval', is_array($value) ? $value : explode(',', (string) $value))));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validateProduct(array $data, ?Product $existing): array
    {
        $type = (string) ($existing?->product_type ?? ($data['product_type'] ?? Product::SIMPLE));
        $req = $existing === null ? 'required' : 'sometimes';
        $noCodes = in_array($type, [Product::VARIABLE], true);

        $rules = [
            'product_type' => ['sometimes', Rule::in(Product::TYPES)],
            'name' => [$req, 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenant.products', 'slug')->ignore($existing?->id)],
            'sku' => [$noCodes ? 'prohibited' : 'sometimes', 'nullable', 'string', 'max:64'],
            'barcode' => [$noCodes ? 'prohibited' : 'sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'price' => [$req, 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'tax_class' => ['sometimes', Rule::in(Product::TAX_CLASSES)],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.brands', 'id')],
            'unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.units_of_measure', 'id')],
            'hsn_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'has_warehouse_pricing' => ['sometimes', 'boolean'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'meta_keywords' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_ids' => ['sometimes', 'array', 'max:50'],
            'category_ids.*' => ['integer'],
            'primary_category_id' => ['sometimes', 'nullable', 'integer'],
            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['integer'],
            'bundle_items' => [$type === Product::BUNDLE && $existing === null ? 'sometimes' : 'prohibited', 'array', 'max:50'],
            'bundle_items.*.child_product_id' => ['required', 'integer'],
            'bundle_items.*.child_product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'bundle_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'custom_fields' => ['sometimes', 'array'],
        ];

        $validator = Validator::make($data, $rules);
        $errors = $validator->errors()->toArray();
        $custom = [];

        try {
            $custom = $this->customFields->validate(self::ENTITY, (array) ($data['custom_fields'] ?? []), CustomFieldService::ADMIN, $existing === null);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();
        $validated['product_type'] = $type;

        return [$validated, $custom];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validateVariant(array $data, bool $creating, ?ProductVariant $existing = null): array
    {
        $validator = Validator::make($data, [
            'sku' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'is_active' => ['sometimes', 'boolean'],
            'option_value_ids' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'option_value_ids.*' => ['integer'],
            'custom_fields' => ['sometimes', 'array'],
        ]);

        $errors = $validator->errors()->toArray();
        $custom = [];

        try {
            $custom = $this->customFields->validate(self::VARIANT_ENTITY, (array) ($data['custom_fields'] ?? []), CustomFieldService::ADMIN, $creating);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [Arr::except($validator->validated(), ['option_value_ids', 'custom_fields']), $custom];
    }
}
