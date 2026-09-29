<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A seller's own products (spec §50.3), through ProductService: every
 * product type, with variants, media, digital files and bundle lines
 * managed through the seller's own sub-routes. Sellers never set
 * seller_id, warehouse pricing, bookable, subscribable or social-commerce
 * channels, and a bundle may contain only the seller's own products.
 * Stock is the tenant's: the marketplace is tenant-fulfilled (UD-20).
 */
final readonly class SellerProductService
{
    /** Fields a seller may send. */
    private const array ALLOWED = ['product_type', 'name', 'slug', 'sku', 'barcode', 'description', 'price', 'compare_at_price', 'cost_price', 'tax_class', 'brand_id',
        'unit_id', 'hsn_code', 'expiry_date', 'low_stock_threshold', 'is_active', 'meta_title', 'meta_description', 'meta_keywords', 'category_ids', 'primary_category_id',
        'tag_ids', 'bundle_items'];

    /** Fields a seller may never set (§50.3). */
    private const array FORBIDDEN = ['seller_id', 'has_warehouse_pricing', 'is_bookable', 'is_subscribable', 'social_commerce_excluded_channels', 'moderation_status', 'moderation_note'];

    public function __construct(
        private ProductService $products,
        private SellerProductModerationService $moderation,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Seller $seller, array $data): Product
    {
        $this->assertAllowed($data);
        $this->assertOwnBundleChildren($seller, (array) ($data['bundle_items'] ?? []));

        return DB::connection('tenant')->transaction(function () use ($seller, $data): Product {
            $product = $this->products->createProduct(Arr::only($data, self::ALLOWED));
            $product->forceFill(['seller_id' => $seller->id, 'moderation_status' => 'not_required'])->save();

            if ((bool) $this->settings->get('seller_product_approval_required', true)) {
                $this->moderation->submit($product);
            } else {
                $product->forceFill(['moderation_status' => 'approved'])->save();
            }

            return $product->refresh();
        });
    }

    /**
     * Edits never re-trigger moderation (A-68).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Seller $seller, Product $product, array $data): Product
    {
        $this->own($seller, $product);
        $this->assertAllowed($data);

        return $this->products->updateProduct($product, Arr::only($data, self::ALLOWED));
    }

    public function delete(Seller $seller, Product $product): void
    {
        $this->products->deleteProduct($this->own($seller, $product));
    }

    /**
     * @param  array{moderation_status?: string, is_active?: bool, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function list(Seller $seller, array $filters = []): LengthAwarePaginator
    {
        return Product::query()->where('seller_id', $seller->id)
            ->when(isset($filters['moderation_status']), static fn ($q) => $q->where('moderation_status', $filters['moderation_status']))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['search']), static fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Another seller's or the store's product is a 404.
     */
    public function own(Seller $seller, Product $product): Product
    {
        if ($product->seller_id !== $seller->id) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$product->id]);
        }

        return $product;
    }

    /**
     * A bundle line (at creation or through the bundle-items route) must
     * name one of the seller's own products.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function assertOwnBundleChildren(Seller $seller, array $items): void
    {
        $ids = array_values(array_filter(array_map(static fn (mixed $i): ?int => is_array($i) && isset($i['child_product_id']) ? (int) $i['child_product_id'] : null, $items)));

        if ($ids !== [] && Product::query()->whereKey($ids)->where(static fn ($q) => $q->whereNull('seller_id')->orWhere('seller_id', '!=', $seller->id))->exists()) {
            throw ApiException::unprocessable('bundle_child_not_owned', 'A bundle may contain only your own products.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertAllowed(array $data): void
    {
        $forbidden = array_values(array_intersect(array_keys($data), self::FORBIDDEN));

        if ($forbidden !== []) {
            throw ValidationException::withMessages(array_fill_keys($forbidden, ['Sellers cannot set this field.']));
        }
    }
}
