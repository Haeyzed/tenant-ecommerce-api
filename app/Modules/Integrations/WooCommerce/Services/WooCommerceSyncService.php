<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\CategoryService;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Integrations\Support\ExternalOrder;
use App\Modules\Integrations\Support\ExternalOrderImporter;
use App\Modules\Integrations\Support\PublicUrlGuard;
use App\Modules\Integrations\Support\SyncRun;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceCategoryMap;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceOrderMap;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceProductMap;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSyncLog;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceTaxRateMap;
use App\Modules\Integrations\WooCommerce\Support\WooCommerceClient;
use App\Modules\Integrations\WooCommerce\Support\WooCommerceException;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tax\Models\TaxRate;
use App\Modules\Tax\Services\TaxService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The WooCommerce sync engine (spec §68.3). Categories, tax rates and
 * products are pulled and mapped; stock is taken from WooCommerce only
 * when a product is first mapped, and from then on this platform is the
 * source of truth and pushes it. Orders are imported (or exported). Every
 * run writes one log row; one failing item never stops the others.
 */
final readonly class WooCommerceSyncService
{
    /** WooCommerce tax classes → ours (§35). */
    private const array TAX_CLASSES = ['standard' => 'standard', 'reduced-rate' => 'reduced', 'zero-rate' => 'exempt'];

    /** WooCommerce order statuses with nothing to import. */
    private const array CLOSED_STATUSES = ['cancelled', 'failed', 'refunded', 'trash', 'checkout-draft'];

    public function __construct(
        private WooCommerceSettingsService $settings,
        private CategoryService $categories,
        private ProductService $products,
        private TaxService $taxes,
        private InventoryService $inventory,
        private ExternalOrderImporter $importer,
    ) {}

    public function syncCategories(string $trigger = 'manual'): WooCommerceSyncLog
    {
        return $this->run('category', 'pull', $trigger, function (WooCommerceClient $client, SyncRun $run): void {
            $remote = $client->all('products/categories');

            // Pass 1: every category exists and is mapped; pass 2: parents.
            foreach ($remote as $item) {
                try {
                    DB::connection('tenant')->transaction(fn () => $this->upsertCategory($item));
                    $run->ok();
                } catch (Throwable $e) {
                    $run->fail('category '.$item['id'], $e);
                }
            }

            $mapped = WooCommerceCategoryMap::query()->pluck('category_id', 'woocommerce_category_id');

            foreach ($remote as $item) {
                $parent = (int) ($item['parent'] ?? 0);

                if (($own = $mapped->get((int) $item['id'])) !== null) {
                    Category::query()->whereKey($own)->update(['parent_id' => $parent === 0 ? null : $mapped->get($parent)]);
                }
            }
        });
    }

    public function syncTaxRates(string $trigger = 'manual'): WooCommerceSyncLog
    {
        return $this->run('tax_rate', 'pull', $trigger, function (WooCommerceClient $client, SyncRun $run): void {
            foreach ($client->all('taxes') as $item) {
                try {
                    DB::connection('tenant')->transaction(fn () => $this->upsertTaxRate($item));
                    $run->ok();
                } catch (Throwable $e) {
                    $run->fail('tax rate '.$item['id'], $e);
                }
            }
        });
    }

    public function syncProducts(string $trigger = 'manual'): WooCommerceSyncLog
    {
        return $this->run('product', 'pull', $trigger, function (WooCommerceClient $client, SyncRun $run, WooCommerceSettings $settings): void {
            $warehouse = $this->warehouse($settings);

            foreach ($client->all('products') as $item) {
                try {
                    $this->upsertProduct($client, $item, $warehouse);
                    $run->ok();
                } catch (Throwable $e) {
                    $run->fail('product '.$item['id'], $e);
                }
            }
        });
    }

    public function syncOrders(string $trigger = 'manual'): WooCommerceSyncLog
    {
        $settings = $this->settings->getSettings();

        if ($settings->order_sync_direction === 'export') {
            return $this->run('order', 'push', $trigger, fn (WooCommerceClient $client, SyncRun $run, WooCommerceSettings $s) => $this->exportOrders($client, $run, $s));
        }

        return $this->run('order', 'pull', $trigger, function (WooCommerceClient $client, SyncRun $run, WooCommerceSettings $s): void {
            $warehouse = $this->warehouse($s);
            $last = WooCommerceSyncLog::query()->where('sync_type', 'order')->where('direction', 'pull')->whereIn('status', ['success', 'partial'])->latest('started_at')->value('started_at');
            // Overlap the previous run by an hour; the order map makes repeats harmless.
            $since = $last !== null ? CarbonImmutable::parse($last)->subHour() : now()->toImmutable()->subDays((int) config('integrations.woocommerce.initial_order_import_days', 30));

            foreach ($client->all('orders', ['modified_after' => $since->utc()->format('Y-m-d\TH:i:s'), 'orderby' => 'date', 'order' => 'asc']) as $item) {
                try {
                    $this->importOrder($item, $warehouse) ? $run->ok() : null;
                } catch (Throwable $e) {
                    $run->fail('order '.$item['id'], $e);
                }
            }
        });
    }

    /**
     * On demand (§68.3): creates the product in WooCommerce, or updates the
     * product it is mapped to, with its current price and available stock.
     */
    public function pushProduct(Product $product): void
    {
        if (! in_array($product->product_type, [Product::SIMPLE, Product::VARIABLE], true)) {
            throw ApiException::unprocessable('product_not_syncable', 'Only simple and variable products can be pushed to WooCommerce.');
        }

        $client = new WooCommerceClient($this->settings->getSettings());
        $product->loadMissing(['variants.optionValues.option']);
        $parent = WooCommerceProductMap::query()->where('product_id', $product->id)->whereNull('product_variant_id')->first();
        $payload = [
            'name' => $product->name,
            'type' => $product->product_type,
            'description' => (string) $product->description,
            'status' => $product->is_active ? 'publish' : 'draft',
        ];

        if ($product->product_type === Product::SIMPLE) {
            $payload += ['regular_price' => self::price($product->price), 'sku' => (string) $product->sku, 'manage_stock' => true, 'stock_quantity' => $this->stockOf($product, null)];
        } else {
            $attributes = [];

            foreach ($product->variants as $variant) {
                foreach ($variant->optionValues as $value) {
                    $attributes[$value->option->name][] = $value->value;
                }
            }

            $payload['attributes'] = array_map(static fn (string $name, array $options): array => ['name' => $name, 'options' => array_values(array_unique($options)), 'variation' => true, 'visible' => true],
                array_keys($attributes), array_values($attributes));
        }

        try {
            $remote = $parent === null ? $client->post('products', $payload) : $client->put('products/'.$parent->woocommerce_product_id, $payload);
        } catch (WooCommerceException $e) {
            throw ApiException::unprocessable('woocommerce_push_failed', $e->getMessage());
        }

        $this->map($product, null, (int) $remote['id'], null);

        if ($product->product_type === Product::VARIABLE) {
            foreach ($product->variants as $variant) {
                $row = WooCommerceProductMap::query()->where('product_variant_id', $variant->id)->first();
                $body = [
                    'regular_price' => self::price($variant->price ?? $product->price), 'sku' => (string) $variant->sku, 'manage_stock' => true,
                    'stock_quantity' => $this->stockOf($product, $variant),
                    'attributes' => $variant->optionValues->map(static fn (ProductOptionValue $v): array => ['name' => $v->option->name, 'option' => $v->value])->values()->all(),
                ];

                try {
                    $made = $row === null ? $client->post("products/{$remote['id']}/variations", $body) : $client->put("products/{$remote['id']}/variations/{$row->woocommerce_variation_id}", $body);
                } catch (WooCommerceException $e) {
                    throw ApiException::unprocessable('woocommerce_push_failed', $e->getMessage());
                }

                $this->map($product, $variant, (int) $remote['id'], (int) $made['id']);
            }
        }
    }

    /**
     * The stock hook (§68.3): mapped rows of these products are marked for
     * the next push.
     *
     * @param  list<int>  $productIds
     */
    public function markStockDirty(array $productIds): int
    {
        return WooCommerceProductMap::query()->whereIn('product_id', $productIds)->update(['stock_dirty_at' => now()]);
    }

    /**
     * PushWooCommerceStock (§68.3): the available stock across warehouses
     * of every dirty product, in batch calls. Rows marked again during the
     * push stay dirty for the next one.
     */
    public function pushDirtyStock(): WooCommerceSyncLog
    {
        return $this->run('stock', 'push', 'scheduled', function (WooCommerceClient $client, SyncRun $run): void {
            $cutoff = now();
            $rows = WooCommerceProductMap::query()->with(['product', 'variant'])->whereNotNull('stock_dirty_at')->where('stock_dirty_at', '<=', $cutoff)->limit(1000)->get();
            $simple = [];
            $variations = [];

            foreach ($rows as $row) {
                // A variable product's parent holds no stock of its own.
                if ($row->product->product_type !== Product::SIMPLE && $row->woocommerce_variation_id === null) {
                    continue;
                }

                $update = ['id' => $row->woocommerce_variation_id ?? $row->woocommerce_product_id, 'manage_stock' => true, 'stock_quantity' => $this->stockOf($row->product, $row->variant)];

                if ($row->woocommerce_variation_id === null) {
                    $simple[] = $update;
                } else {
                    $variations[$row->woocommerce_product_id][] = $update;
                }
            }

            foreach (array_chunk($simple, 100) as $chunk) {
                $this->batch($client, $run, 'products/batch', $chunk);
            }

            foreach ($variations as $parentId => $updates) {
                foreach (array_chunk($updates, 100) as $chunk) {
                    $this->batch($client, $run, "products/{$parentId}/variations/batch", $chunk);
                }
            }

            WooCommerceProductMap::query()->whereKey($rows->modelKeys())->where('stock_dirty_at', '<=', $cutoff)->update(['stock_dirty_at' => null, 'last_synced_at' => now()]);
        });
    }

    /**
     * @param  array{sync_type?: string, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, WooCommerceSyncLog>
     */
    public function getSyncLogs(array $filters): LengthAwarePaginator
    {
        return WooCommerceSyncLog::query()
            ->when(isset($filters['sync_type']), static fn ($q) => $q->where('sync_type', $filters['sync_type']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('started_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Runs by type and status, and the last run of each type.
     *
     * @return array<string, mixed>
     */
    public function getSyncMetrics(): array
    {
        $counts = WooCommerceSyncLog::query()->selectRaw('sync_type, status, count(*) as runs')->groupBy('sync_type', 'status')->get();
        $metrics = [];

        foreach (WooCommerceSyncLog::TYPES as $type) {
            $last = WooCommerceSyncLog::query()->where('sync_type', $type)->latest('started_at')->first();
            $metrics[$type] = [
                'runs' => array_merge(array_fill_keys(WooCommerceSyncLog::STATUSES, 0), $counts->where('sync_type', $type)->pluck('runs', 'status')->map(static fn ($n): int => (int) $n)->all()),
                'last_run_at' => $last?->started_at?->toIso8601String(),
                'last_status' => $last?->status,
            ];
        }

        return [
            'types' => $metrics,
            'mapped' => [
                'categories' => WooCommerceCategoryMap::query()->count(),
                'products' => WooCommerceProductMap::query()->whereNull('woocommerce_variation_id')->count(),
                'variations' => WooCommerceProductMap::query()->whereNotNull('woocommerce_variation_id')->count(),
                'tax_rates' => WooCommerceTaxRateMap::query()->count(),
                'orders' => WooCommerceOrderMap::query()->count(),
            ],
            'last_synced_at' => $this->settings->getSettings()->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @param  callable(WooCommerceClient, SyncRun, WooCommerceSettings): void  $work
     */
    private function run(string $type, string $direction, string $trigger, callable $work): WooCommerceSyncLog
    {
        $settings = $this->settings->getSettings();
        $run = new SyncRun(new WooCommerceSyncLog, ['sync_type' => $type, 'direction' => $direction, 'trigger' => $trigger]);

        try {
            $work(new WooCommerceClient($settings), $run, $settings);

            /** @var WooCommerceSyncLog */
            return $run->finish();
        } catch (Throwable $e) {
            $expected = $e instanceof WooCommerceException || $e instanceof ApiException;

            if (! $expected) {
                report($e);
            }

            /** @var WooCommerceSyncLog */
            return $run->abort($expected ? $e : 'Unexpected error: '.class_basename($e));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertCategory(array $item): void
    {
        $map = WooCommerceCategoryMap::query()->where('woocommerce_category_id', (int) $item['id'])->first();
        $name = html_entity_decode((string) $item['name'], ENT_QUOTES);
        $category = $map === null ? null : Category::query()->find($map->category_id);

        if ($category !== null) {
            $this->categories->updateCategory($category, ['name' => $name]);
        } else {
            $slug = Str::slug((string) ($item['slug'] ?? $name)) ?: 'category-'.$item['id'];
            // An unmapped category with the same slug is the same category.
            $category = Category::query()->where('slug', $slug)->whereNotIn('id', WooCommerceCategoryMap::query()->select('category_id'))->first()
                ?? $this->categories->createCategory(['name' => $name, 'slug' => Category::query()->where('slug', $slug)->exists() ? $slug.'-'.$item['id'] : $slug]);
            $map ??= new WooCommerceCategoryMap;
            $map->forceFill(['category_id' => $category->id, 'woocommerce_category_id' => (int) $item['id']]);
        }

        $map->forceFill(['last_synced_at' => now()])->save();
    }

    /**
     * An existing rate for the same place, class and percentage is mapped,
     * not duplicated (§68.3).
     *
     * @param  array<string, mixed>  $item
     */
    private function upsertTaxRate(array $item): void
    {
        $iso = strtoupper((string) ($item['country'] ?? ''));

        if ($iso === '') {
            throw new \RuntimeException('Rates that apply to every country are not supported; add the country in WooCommerce.');
        }

        $countryId = DB::connection('landlord')->table('countries')->where('iso2', $iso)->value('id')
            ?? throw new \RuntimeException("Unknown country {$iso}.");
        $state = (string) ($item['state'] ?? '');
        $stateId = $state === '' ? null : (DB::connection('landlord')->table('states')->where('country_id', $countryId)->where('state_code', $state)->value('id')
            ?? throw new \RuntimeException("Unknown state {$state} in {$iso}."));
        $class = self::TAX_CLASSES[(string) ($item['class'] ?? 'standard')] ?? throw new \RuntimeException('Tax class '.$item['class'].' has no equivalent here.');
        $rate = bcadd((string) $item['rate'], '0', 4);
        $data = ['name' => mb_substr((string) ($item['name'] ?: 'Tax'), 0, 100), 'country_id' => (int) $countryId, 'state_id' => $stateId === null ? null : (int) $stateId, 'tax_class' => $class, 'rate_percentage' => $rate];

        $map = WooCommerceTaxRateMap::query()->where('woocommerce_tax_rate_id', (int) $item['id'])->first();
        $existing = $map === null ? null : TaxRate::query()->find($map->tax_rate_id);

        if ($existing !== null) {
            $this->taxes->updateTaxRate($existing, $data);
        } else {
            $existing = TaxRate::query()->where('country_id', $data['country_id'])->where('state_id', $data['state_id'])->where('tax_class', $class)->where('rate_percentage', $rate)->first()
                ?? $this->taxes->createTaxRate($data);
            $map ??= new WooCommerceTaxRateMap;
            $map->forceFill(['tax_rate_id' => $existing->id, 'woocommerce_tax_rate_id' => (int) $item['id']]);
        }

        $map->forceFill(['last_synced_at' => now()])->save();
    }

    /**
     * Creates or updates the product (and its variations). A new mapping
     * matches an unmapped product with the same SKU instead of creating a
     * second one, brings in the images, and takes WooCommerce's stock once.
     *
     * @param  array<string, mixed>  $item
     */
    private function upsertProduct(WooCommerceClient $client, array $item, Warehouse $warehouse): void
    {
        $type = (string) ($item['type'] ?? '');

        if (! in_array($type, [Product::SIMPLE, Product::VARIABLE], true)) {
            throw new \RuntimeException("WooCommerce {$type} products are not imported; only simple and variable ones.");
        }

        $variations = $type === Product::VARIABLE ? $client->all("products/{$item['id']}/variations") : [];
        $categoryIds = WooCommerceCategoryMap::query()->whereIn('woocommerce_category_id', array_map(static fn (array $c): int => (int) $c['id'], (array) ($item['categories'] ?? [])))->pluck('category_id')->all();
        $price = $type === Product::SIMPLE ? self::amount($item['regular_price'] ?? $item['price'] ?? null)
            : (collect($variations)->map(static fn (array $v): string => self::amount($v['regular_price'] ?? $v['price'] ?? null))->sort()->first() ?? '0');
        $data = [
            'name' => html_entity_decode((string) $item['name'], ENT_QUOTES),
            'description' => (string) ($item['description'] ?? ''),
            'price' => $price,
            'is_active' => ($item['status'] ?? 'publish') === 'publish',
            'category_ids' => $categoryIds,
        ];

        $created = false;

        $product = DB::connection('tenant')->transaction(function () use ($item, $type, $data, $warehouse, &$created): Product {
            $map = WooCommerceProductMap::query()->where('woocommerce_product_id', (int) $item['id'])->whereNull('woocommerce_variation_id')->first();
            $product = $map === null ? null : Product::query()->find($map->product_id);

            if ($product !== null) {
                return $this->products->updateProduct($product, $data);
            }

            $sku = trim((string) ($item['sku'] ?? ''));
            $product = $sku === '' ? null : Product::query()->where('sku', $sku)->where('product_type', $type)
                ->whereNotIn('id', WooCommerceProductMap::query()->select('product_id'))->first();

            if ($product === null) {
                $product = $this->products->createProduct([...$data, 'product_type' => $type,
                    ...($sku !== '' && $type === Product::SIMPLE && ! Product::withTrashed()->where('sku', $sku)->exists() ? ['sku' => $sku] : [])]);
                $created = true;
            }

            $this->map($product, null, (int) $item['id'], null);

            // Stock comes from WooCommerce only now, on the first mapping (§68.3).
            if ($type === Product::SIMPLE) {
                $this->importStock($warehouse, $product, null, $item);
            }

            return $product;
        });

        foreach ($variations as $variation) {
            DB::connection('tenant')->transaction(fn () => $this->upsertVariation($product, (int) $item['id'], $variation, $warehouse));
        }

        if ($created) {
            $this->importImages($product, (array) ($item['images'] ?? []));
        }
    }

    /**
     * @param  array<string, mixed>  $variation
     */
    private function upsertVariation(Product $product, int $parentId, array $variation, Warehouse $warehouse): void
    {
        $price = self::amount($variation['regular_price'] ?? $variation['price'] ?? null);
        $map = WooCommerceProductMap::query()->where('woocommerce_product_id', $parentId)->where('woocommerce_variation_id', (int) $variation['id'])->first();
        $variant = $map === null ? null : ProductVariant::query()->find($map->product_variant_id);

        if ($variant !== null) {
            $this->products->updateVariant($variant, ['price' => $price]);
            $map->forceFill(['last_synced_at' => now()])->save();

            return;
        }

        $sku = trim((string) ($variation['sku'] ?? '')) ?: 'WOO-'.$variation['id'];
        $variant = ProductVariant::query()->where('product_id', $product->id)->where('sku', $sku)->first();

        if ($variant === null) {
            $valueIds = [];

            foreach ((array) ($variation['attributes'] ?? []) as $attribute) {
                $option = ProductOption::query()->firstOrCreate(['name' => mb_substr((string) $attribute['name'], 0, 64)], ['sort_order' => 0]);
                $value = $option->values()->firstOrCreate(['value' => mb_substr((string) $attribute['option'], 0, 64)], ['sort_order' => 0]);
                $valueIds[] = $value->id;
            }

            $variant = $this->products->createVariant($product, $valueIds, [
                'option_value_ids' => $valueIds,
                'sku' => ProductVariant::withTrashed()->where('sku', $sku)->exists() ? $sku.'-'.$variation['id'] : $sku,
                'price' => $price,
            ]);
        }

        $this->map($product, $variant, $parentId, (int) $variation['id']);
        $this->importStock($warehouse, $product, $variant, $variation);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function importStock(Warehouse $warehouse, Product $product, ?ProductVariant $variant, array $item): void
    {
        $quantity = (int) ($item['stock_quantity'] ?? 0);

        if (($item['manage_stock'] ?? false) === true && $quantity > 0) {
            $this->inventory->adjustStock($warehouse, $product, $variant, (string) $quantity, 'adjustment_in', null, 'woocommerce_import');
        }
    }

    /**
     * Up to ten images of a newly imported product, each from a public
     * HTTPS address and at most 5 MB; an image that fails is skipped.
     *
     * @param  list<array<string, mixed>>  $images
     */
    private function importImages(Product $product, array $images): void
    {
        foreach (array_slice($images, 0, 10) as $image) {
            $path = null;

            try {
                $url = PublicUrlGuard::assertPublic((string) ($image['src'] ?? ''));
                $response = Http::connectTimeout(10)->timeout(30)->withoutRedirecting()->get($url);
                $type = (string) $response->header('Content-Type');

                if (! $response->successful() || ! str_starts_with($type, 'image/') || strlen($response->body()) > 5 * 1024 * 1024) {
                    continue;
                }

                $path = tempnam(sys_get_temp_dir(), 'woo');
                file_put_contents((string) $path, $response->body());
                $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image.jpg';
                $this->products->attachMedia($product, new UploadedFile((string) $path, $name, $type, null, true));
            } catch (Throwable) {
                continue;
            } finally {
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Imports one WooCommerce order, or brings a mapped one up to date
     * (paid there → paid here; cancelled there → cancelled here while
     * unconfirmed). Returns false when there was nothing to do.
     *
     * @param  array<string, mixed>  $item
     */
    private function importOrder(array $item, Warehouse $warehouse): bool
    {
        $status = (string) ($item['status'] ?? '');
        $map = WooCommerceOrderMap::query()->where('woocommerce_order_id', (int) $item['id'])->first();

        if ($map !== null) {
            $order = Order::query()->find($map->order_id);

            if ($order === null) {
                return false;
            }

            if (in_array($status, ['cancelled', 'failed'], true)) {
                $this->importer->cancelIfOpen($order, 'Cancelled in WooCommerce');
            } elseif (self::isPaid($item)) {
                $this->importer->syncPayment($order, new ExternalOrder('woocommerce', 'woo:'.$item['id'], (string) $item['currency'], null, null, null, null, [], '0', '0', (string) $order->total, true, 'Paid via WooCommerce', null));
            }

            $map->forceFill(['last_synced_at' => now()])->save();

            return true;
        }

        if (in_array($status, self::CLOSED_STATUSES, true)) {
            return false;
        }

        $lines = [];

        foreach ((array) ($item['line_items'] ?? []) as $line) {
            $variationId = (int) ($line['variation_id'] ?? 0);
            $row = WooCommerceProductMap::query()->with(['product', 'variant'])->where('woocommerce_product_id', (int) $line['product_id'])
                ->where('woocommerce_variation_id', $variationId === 0 ? null : $variationId)->first();

            if ($row === null) {
                throw new \RuntimeException("{$line['name']} is not mapped to a product here. Sync products first.");
            }

            $quantity = Quantity::normalize((string) $line['quantity']);
            $subtotal = Money::normalize((string) $line['subtotal']);
            $lines[] = [
                'product' => $row->product,
                'variant' => $row->variant,
                'quantity' => $quantity,
                'unit_price' => bcdiv($subtotal, $quantity, 4),
                'discount_amount' => Money::sub($subtotal, Money::normalize((string) $line['total'])),
                'tax_amount' => Money::normalize((string) $line['total_tax']),
            ];
        }

        $billing = (array) ($item['billing'] ?? []);
        $shipping = (array) ($item['shipping'] ?? []);
        $address = ($shipping['address_1'] ?? '') !== '' ? $shipping : $billing;

        $order = $this->importer->import(new ExternalOrder(
            source: 'woocommerce',
            idempotencyKey: 'woo:'.$item['id'],
            currency: (string) $item['currency'],
            customerEmail: ($billing['email'] ?? '') ?: null,
            customerName: trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: null,
            customerPhone: ($billing['phone'] ?? '') ?: null,
            shippingAddress: ($address['address_1'] ?? '') === '' ? null : self::address($address, (string) ($billing['phone'] ?? '')),
            lines: $lines,
            shippingAmount: Money::normalize((string) ($item['shipping_total'] ?? '0')),
            shippingTax: Money::normalize((string) ($item['shipping_tax'] ?? '0')),
            total: Money::normalize((string) $item['total']),
            paid: self::isPaid($item),
            paidNote: 'Paid via WooCommerce',
            placedAt: isset($item['date_created_gmt']) ? CarbonImmutable::parse($item['date_created_gmt'], 'UTC') : null,
        ), $warehouse);

        $row = new WooCommerceOrderMap;
        $row->forceFill(['order_id' => $order->id, 'woocommerce_order_id' => (int) $item['id'], 'direction' => 'imported', 'last_synced_at' => now()])->save();

        return true;
    }

    /**
     * Platform orders confirmed since sync was first switched on, not
     * imported from WooCommerce, whose every line is a mapped product.
     */
    private function exportOrders(WooCommerceClient $client, SyncRun $run, WooCommerceSettings $settings): void
    {
        $orders = Order::query()->with(['items'])->whereNotNull('confirmed_at')->where('confirmed_at', '>=', $settings->activated_at ?? now())
            ->where('order_source', '!=', 'woocommerce')->whereNotIn('id', WooCommerceOrderMap::query()->select('order_id'))
            ->orderBy('id')->limit(100)->get();

        foreach ($orders as $order) {
            try {
                $items = [];

                foreach ($order->items as $item) {
                    $row = WooCommerceProductMap::query()->where('product_id', $item->product_id)->where('product_variant_id', $item->product_variant_id)->first()
                        ?? throw new \RuntimeException("{$item->name_snapshot} is not in WooCommerce. Push the product first.");
                    $items[] = [
                        'product_id' => $row->woocommerce_product_id, 'variation_id' => $row->woocommerce_variation_id ?? 0,
                        'quantity' => (float) $item->quantity,
                        'subtotal' => self::price(bcmul((string) $item->unit_price, (string) $item->quantity, 4)),
                        'total' => self::price(Money::sub(bcmul((string) $item->unit_price, (string) $item->quantity, 4), (string) $item->discount_amount)),
                    ];
                }

                $address = (array) ($order->shipping_address ?? []);
                $remote = $client->post('orders', [
                    'status' => $order->payment_status === 'paid' ? 'processing' : 'pending',
                    'set_paid' => $order->payment_status === 'paid',
                    'currency' => $order->currency_code,
                    'customer_note' => 'Order '.$order->order_number,
                    'billing' => ['first_name' => (string) $order->customer_name, 'email' => (string) $order->customer_email, 'phone' => (string) $order->customer_phone],
                    'shipping' => ['first_name' => (string) ($address['name'] ?? $order->customer_name), 'address_1' => (string) ($address['line1'] ?? ''), 'address_2' => (string) ($address['line2'] ?? ''), 'postcode' => (string) ($address['postal_code'] ?? '')],
                    'line_items' => $items,
                    'shipping_lines' => Money::isPositive((string) $order->shipping_amount) ? [['method_id' => 'flat_rate', 'method_title' => 'Shipping', 'total' => self::price((string) $order->shipping_amount)]] : [],
                ]);

                $map = new WooCommerceOrderMap;
                $map->forceFill(['order_id' => $order->id, 'woocommerce_order_id' => (int) $remote['id'], 'direction' => 'exported', 'last_synced_at' => now()])->save();
                $run->ok();
            } catch (Throwable $e) {
                $run->fail('order '.$order->order_number, $e);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    private function batch(WooCommerceClient $client, SyncRun $run, string $path, array $updates): void
    {
        try {
            $client->post($path, ['update' => $updates]);

            foreach ($updates as $update) {
                $run->ok();
            }
        } catch (WooCommerceException $e) {
            foreach ($updates as $update) {
                $run->fail('stock '.$update['id'], $e);
            }
        }
    }

    private function map(Product $product, ?ProductVariant $variant, int $remoteId, ?int $variationId): void
    {
        $row = WooCommerceProductMap::query()->where('woocommerce_product_id', $remoteId)->where('woocommerce_variation_id', $variationId)->first() ?? new WooCommerceProductMap;
        $row->forceFill([
            'product_id' => $product->id, 'product_variant_id' => $variant?->id, 'woocommerce_product_id' => $remoteId,
            'woocommerce_variation_id' => $variationId, 'last_synced_at' => now(),
        ])->save();
    }

    private function warehouse(WooCommerceSettings $settings): Warehouse
    {
        return Warehouse::query()->whereKey($settings->import_warehouse_id)->where('is_active', true)->first()
            ?? throw new WooCommerceException('Choose an active import warehouse in the WooCommerce settings.');
    }

    private function stockOf(Product $product, ?ProductVariant $variant): int
    {
        $available = $this->inventory->getAvailableStock($product, $variant) ?? '0';

        return max(0, (int) floor((float) $available));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function isPaid(array $item): bool
    {
        return ! empty($item['date_paid']) || in_array($item['status'] ?? '', ['processing', 'completed'], true);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private static function address(array $a, string $phone): array
    {
        $countryId = ($a['country'] ?? '') === '' ? null : DB::connection('landlord')->table('countries')->where('iso2', strtoupper((string) $a['country']))->value('id');
        $stateId = $countryId === null || ($a['state'] ?? '') === '' ? null
            : DB::connection('landlord')->table('states')->where('country_id', $countryId)->where('state_code', (string) $a['state'])->value('id');

        return [
            'name' => trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')),
            'phone' => ($a['phone'] ?? '') ?: ($phone ?: null),
            'line1' => (string) $a['address_1'],
            'line2' => ($a['address_2'] ?? '') ?: null,
            'city' => ($a['city'] ?? '') ?: null,
            'city_id' => null,
            'state_id' => $stateId === null ? null : (int) $stateId,
            'country_id' => $countryId === null ? null : (int) $countryId,
            'postal_code' => ($a['postcode'] ?? '') ?: null,
        ];
    }

    private static function amount(mixed $value): string
    {
        return is_numeric($value) ? Money::normalize((string) $value) : Money::normalize(0);
    }

    private static function price(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
