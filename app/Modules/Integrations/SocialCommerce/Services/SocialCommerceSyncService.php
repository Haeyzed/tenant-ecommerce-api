<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Services;

use App\Modules\Cart\Services\PricingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Integrations\SocialCommerce\Drivers\SocialCommerceException;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceOrderMap;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceProductMap;
use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceSyncLog;
use App\Modules\Integrations\Support\ExternalOrder;
use App\Modules\Integrations\Support\ExternalOrderImporter;
use App\Modules\Integrations\Support\SyncRun;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Channel sync (spec §69.2). Products are pushed from our own catalogue,
 * price and stock always read from our tables, never kept on the channel
 * side; excluded, inactive and non-physical products are taken down.
 * Orders from native checkout are imported like WooCommerce's (A-60).
 */
final readonly class SocialCommerceSyncService
{
    public function __construct(
        private SocialCommerceAccountService $accounts,
        private PricingService $pricing,
        private CurrencyService $currencies,
        private InventoryService $inventory,
        private ExternalOrderImporter $importer,
    ) {}

    public function syncProducts(SocialCommerceAccount $account, string $trigger = 'manual'): SocialCommerceSyncLog
    {
        return $this->run($account, 'product', $trigger, function (SyncRun $run) use ($account): void {
            $eligible = $this->eligible($account)->with(['variants' => static fn ($q) => $q->where('is_active', true)])->orderBy('id')->get();

            foreach ($eligible->chunk(50) as $chunk) {
                $this->push($account, $chunk->all(), $run);
            }

            // Anything mapped that is no longer eligible comes down.
            $stale = SocialCommerceProductMap::query()->where('social_commerce_account_id', $account->id)->whereNotIn('product_id', $eligible->modelKeys() ?: [0])->get();

            foreach ($stale as $map) {
                try {
                    $this->takeDown($account, $map);
                    $run->ok();
                } catch (Throwable $e) {
                    $run->fail('product '.$map->product_id, $e);
                }
            }
        });
    }

    public function syncOrders(SocialCommerceAccount $account, string $trigger = 'manual'): SocialCommerceSyncLog
    {
        return $this->run($account, 'order', $trigger, function (SyncRun $run) use ($account): void {
            if (! $account->sync_orders || ! $account->takesOrders()) {
                throw new SocialCommerceException('Order sync is off for this account.');
            }

            $warehouse = Warehouse::query()->whereKey($account->fulfilment_warehouse_id)->where('is_active', true)->first()
                ?? throw new SocialCommerceException('Choose an active fulfilment warehouse for this account.');
            $last = SocialCommerceSyncLog::query()->where('social_commerce_account_id', $account->id)->where('sync_type', 'order')
                ->whereIn('status', ['success', 'partial'])->latest('started_at')->value('started_at');
            $since = $last !== null ? CarbonImmutable::parse($last)->subHour() : now()->toImmutable()->subDays((int) config('integrations.social_commerce.initial_order_import_days', 7));

            foreach ($this->accounts->driver($account)->orders($account, $since) as $order) {
                try {
                    $this->importOrder($account, $order, $warehouse) ? $run->ok() : null;
                } catch (Throwable $e) {
                    $run->fail('order '.$order['id'], $e);
                }
            }
        });
    }

    public function pushProduct(SocialCommerceAccount $account, Product $product): SocialCommerceProductMap
    {
        if (! $this->eligible($account)->whereKey($product->id)->exists()) {
            throw ApiException::unprocessable('product_not_syncable', 'Only active simple and variable products not excluded from this channel can be listed.');
        }

        $run = new SyncRun(new SocialCommerceSyncLog, ['social_commerce_account_id' => $account->id, 'sync_type' => 'product', 'trigger' => 'manual']);

        try {
            $this->push($account, [$product->load(['variants' => static fn ($q) => $q->where('is_active', true)])], $run);
            $run->finish();
        } catch (SocialCommerceException $e) {
            $run->abort($e);

            throw ApiException::unprocessable('social_commerce_push_failed', $e->getMessage());
        }

        return SocialCommerceProductMap::query()->where('social_commerce_account_id', $account->id)->where('product_id', $product->id)->firstOrFail();
    }

    public function removeProduct(SocialCommerceAccount $account, Product $product): void
    {
        $map = SocialCommerceProductMap::query()->where('social_commerce_account_id', $account->id)->where('product_id', $product->id)->first();

        if ($map === null) {
            return;
        }

        try {
            $this->takeDown($account, $map);
        } catch (SocialCommerceException $e) {
            throw ApiException::unprocessable('social_commerce_remove_failed', $e->getMessage());
        }
    }

    /**
     * @param  array{account_id?: int, sync_type?: string, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, SocialCommerceSyncLog>
     */
    public function getSyncLogs(array $filters): LengthAwarePaginator
    {
        return SocialCommerceSyncLog::query()
            ->when(isset($filters['account_id']), static fn ($q) => $q->where('social_commerce_account_id', $filters['account_id']))
            ->when(isset($filters['sync_type']), static fn ($q) => $q->where('sync_type', $filters['sync_type']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('started_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * Per account: listings by status, imported orders, and the last run of
     * each type.
     *
     * @return list<array<string, mixed>>
     */
    public function getSyncMetrics(): array
    {
        return $this->accounts->listAccounts()->map(static function (SocialCommerceAccount $account): array {
            $last = static fn (string $type): ?SocialCommerceSyncLog => SocialCommerceSyncLog::query()->where('social_commerce_account_id', $account->id)->where('sync_type', $type)->latest('started_at')->first();

            return [
                'account_id' => $account->id,
                'channel' => $account->channel,
                'is_active' => $account->is_active,
                'listings' => array_merge(array_fill_keys(SocialCommerceProductMap::STATUSES, 0), SocialCommerceProductMap::query()->where('social_commerce_account_id', $account->id)
                    ->selectRaw('sync_status, count(*) as n')->groupBy('sync_status')->pluck('n', 'sync_status')->map(static fn ($n): int => (int) $n)->all()),
                'orders_imported' => SocialCommerceOrderMap::query()->where('social_commerce_account_id', $account->id)->count(),
                'last_product_sync' => ['at' => $last('product')?->started_at?->toIso8601String(), 'status' => $last('product')?->status],
                'last_order_sync' => ['at' => $last('order')?->started_at?->toIso8601String(), 'status' => $last('order')?->status],
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Active simple and variable products the channel may list (A-61).
     *
     * @return Builder<Product>
     */
    private function eligible(SocialCommerceAccount $account): Builder
    {
        return Product::query()->visible()->whereIn('product_type', [Product::SIMPLE, Product::VARIABLE])
            ->where(static fn ($q) => $q->whereNull('social_commerce_excluded_channels')->orWhereJsonDoesntContain('social_commerce_excluded_channels', $account->channel));
    }

    /**
     * @param  list<Product>  $products
     */
    private function push(SocialCommerceAccount $account, array $products, SyncRun $run): void
    {
        $items = [];
        $owner = [];

        foreach ($products as $product) {
            foreach ($this->items($product) as $item) {
                $items[] = $item;
                $owner[$item['retailer_id']] = $product->id;
            }
        }

        if ($items === []) {
            return;
        }

        $results = $this->accounts->driver($account)->upsert($account, $items);

        foreach ($products as $product) {
            $mine = array_filter($results, static fn (string $retailerId): bool => ($owner[$retailerId] ?? null) === $product->id, ARRAY_FILTER_USE_KEY);
            $rejections = array_values(array_filter(array_column($mine, 'rejection')));
            $external = collect($mine)->pluck('external_id')->filter()->first();
            $map = SocialCommerceProductMap::query()->where('social_commerce_account_id', $account->id)->where('product_id', $product->id)->first() ?? new SocialCommerceProductMap;
            $map->forceFill([
                'social_commerce_account_id' => $account->id,
                'product_id' => $product->id,
                // Meta keys items by our retailer id; the product's group is its id there.
                'external_product_id' => $account->channel === SocialCommerceAccount::TIKTOK_SHOP ? $external : 'p'.$product->id,
                'sync_status' => $rejections === [] ? 'synced' : 'rejected',
                'rejection_reason' => $rejections === [] ? null : mb_substr((string) $rejections[0], 0, 255),
                'last_synced_at' => now(),
            ])->save();

            $rejections === [] ? $run->ok() : $run->fail('product '.$product->id, (string) $rejections[0]);
        }
    }

    /**
     * One item per product, or per active variant, priced and stocked from
     * our own tables in the base currency.
     *
     * @return list<array<string, mixed>>
     */
    private function items(Product $product): array
    {
        $tenant = tenant();
        $currency = $this->currencies->baseCurrency();
        $link = $tenant instanceof Tenant ? FrontendUrl::storefront($tenant, '/products/'.$product->slug) : null;
        $image = ($product->getFirstMediaUrl('featured') ?: $product->getFirstMediaUrl('gallery')) ?: null;
        $item = fn (?ProductVariant $variant): array => [
            'retailer_id' => 'p'.$product->id.($variant === null ? '' : '-v'.$variant->id),
            'group_id' => 'p'.$product->id,
            'sku' => $variant?->sku ?? $product->sku,
            'title' => $product->name,
            'description' => strip_tags((string) $product->description),
            'price' => bcadd($this->pricing->resolveUnitPrice($product, $variant, null, $currency, '1')->unitPrice, '0', 2),
            'currency' => $currency,
            'quantity' => max(0, (int) floor((float) ($this->inventory->getAvailableStock($product, $variant) ?? '0'))),
            'link' => $link,
            'image_link' => $image,
        ];

        if ($product->product_type === Product::SIMPLE) {
            return [$item(null)];
        }

        return $product->variants->map(static fn (ProductVariant $v): array => $item($v))->values()->all();
    }

    private function takeDown(SocialCommerceAccount $account, SocialCommerceProductMap $map): void
    {
        if ($map->external_product_id !== null) {
            $product = Product::withTrashed()->with('variants')->find($map->product_id);
            // Meta items are keyed by retailer id: the product's, or each variant's.
            $ids = $account->channel === SocialCommerceAccount::TIKTOK_SHOP || $product === null || $product->product_type === Product::SIMPLE
                ? [$map->external_product_id]
                : $product->variants->map(static fn (ProductVariant $v): string => 'p'.$product->id.'-v'.$v->id)->values()->all();
            $this->accounts->driver($account)->remove($account, $ids);
        }

        $map->delete();
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function importOrder(SocialCommerceAccount $account, array $order, Warehouse $warehouse): bool
    {
        $key = 'social:'.$account->id.':'.$order['id'];
        $map = SocialCommerceOrderMap::query()->where('social_commerce_account_id', $account->id)->where('external_order_id', (string) $order['id'])->first();
        $existing = $map === null ? null : Order::query()->find($map->order_id);

        if ($existing !== null) {
            if ($order['cancelled']) {
                $this->importer->cancelIfOpen($existing, 'Cancelled on '.$account->channel);
            } elseif ($order['paid']) {
                $this->importer->syncPayment($existing, new ExternalOrder('social', $key, (string) $order['currency'], null, null, null, null, [], '0', '0', (string) $existing->total, true, 'Paid via '.$account->channel, null));
            }

            $map?->forceFill(['last_synced_at' => now()])->save();

            return true;
        }

        if ($order['cancelled']) {
            return false;
        }

        $lines = [];

        foreach ($order['lines'] as $line) {
            [$product, $variant] = $this->match($line);
            $quantity = Quantity::normalize((string) $line['quantity']);
            $lines[] = [
                'product' => $product, 'variant' => $variant, 'quantity' => $quantity,
                'unit_price' => Money::normalize((string) $line['unit_price']), 'discount_amount' => Money::normalize(0), 'tax_amount' => Money::normalize((string) $line['tax']),
            ];
        }

        $address = $order['address'];

        $created = $this->importer->import(new ExternalOrder(
            source: 'social',
            idempotencyKey: $key,
            currency: (string) $order['currency'],
            customerEmail: $order['email'],
            customerName: $order['name'],
            customerPhone: $order['phone'],
            shippingAddress: $address === null ? null : [
                'name' => $address['name'], 'phone' => $order['phone'], 'line1' => (string) $address['line1'], 'line2' => $address['line2'],
                'city' => $address['city'], 'city_id' => null, 'state_id' => null,
                'country_id' => ($address['country'] ?? null) === null ? null : (DB::connection('landlord')->table('countries')->where('iso2', strtoupper((string) $address['country']))->value('id')),
                'postal_code' => $address['postal_code'],
            ],
            lines: $lines,
            shippingAmount: Money::normalize((string) $order['shipping']),
            shippingTax: Money::normalize((string) $order['shipping_tax']),
            total: Money::normalize((string) $order['total']),
            paid: (bool) $order['paid'],
            paidNote: 'Paid via '.$account->channel,
            placedAt: $order['created_at'] === null ? null : CarbonImmutable::parse((string) $order['created_at']),
        ), $warehouse);

        $row = new SocialCommerceOrderMap;
        $row->forceFill(['social_commerce_account_id' => $account->id, 'order_id' => $created->id, 'external_order_id' => (string) $order['id'], 'last_synced_at' => now()])->save();

        return true;
    }

    /**
     * Our product for a channel line: by retailer id (Meta) or SKU (TikTok).
     *
     * @param  array<string, mixed>  $line
     * @return array{0: Product, 1: ProductVariant|null}
     */
    private function match(array $line): array
    {
        if (($line['retailer_id'] ?? null) !== null && preg_match('/^p(\d+)(?:-v(\d+))?$/', (string) $line['retailer_id'], $m) === 1) {
            $product = Product::query()->find((int) $m[1]);
            $variant = isset($m[2]) ? ProductVariant::query()->where('product_id', (int) $m[1])->find((int) $m[2]) : null;

            if ($product !== null && (! isset($m[2]) || $variant !== null)) {
                return [$product, $variant];
            }
        }

        $sku = (string) ($line['sku'] ?? '');
        $variant = $sku === '' ? null : ProductVariant::query()->where('sku', $sku)->first();

        if ($variant !== null) {
            return [Product::query()->findOrFail($variant->product_id), $variant];
        }

        $product = $sku === '' ? null : Product::query()->where('sku', $sku)->first();

        return $product !== null ? [$product, null] : throw new \RuntimeException('A line ('.($line['retailer_id'] ?? $sku).') matches no product here.');
    }

    /**
     * @param  callable(SyncRun): void  $work
     */
    private function run(SocialCommerceAccount $account, string $type, string $trigger, callable $work): SocialCommerceSyncLog
    {
        $run = new SyncRun(new SocialCommerceSyncLog, ['social_commerce_account_id' => $account->id, 'sync_type' => $type, 'trigger' => $trigger]);

        try {
            $work($run);

            /** @var SocialCommerceSyncLog */
            return $run->finish();
        } catch (Throwable $e) {
            $expected = $e instanceof SocialCommerceException || $e instanceof ApiException;

            if (! $expected) {
                report($e);
            }

            /** @var SocialCommerceSyncLog */
            return $run->abort($expected ? $e : 'Unexpected error: '.class_basename($e));
        }
    }
}
