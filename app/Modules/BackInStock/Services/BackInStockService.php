<?php

declare(strict_types=1);

namespace App\Modules\BackInStock\Services;

use App\Modules\BackInStock\Models\BackInStockSubscription;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBundleItem;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use App\Shared\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * "Notify me when available" (spec §56). Uniqueness of the waiting row is
 * a database rule (pending_key); a repeat request returns the row already
 * waiting. The alert fires when stock goes from none to some (§32.7), for
 * the product or variant and for any bundle that restock makes available.
 */
final readonly class BackInStockService
{
    public function __construct(
        private InventoryService $inventory,
        private NotificationDispatchService $notifications,
        private TenantSettingsService $settings,
    ) {}

    /**
     * Rejected while the item is in stock, and for products whose stock is
     * not tracked (digital and service products are never out of stock).
     */
    public function subscribe(Product $product, string $email, ?Customer $customer = null, ?ProductVariant $variant = null): BackInStockSubscription
    {
        if ($variant !== null && ($variant->product_id !== $product->id || ! $variant->is_active)) {
            throw ApiException::unprocessable('variant_invalid', 'Choose an option of this product.');
        }

        $available = $this->inventory->getAvailableStock($product, $variant);

        if ($available === null) {
            throw ApiException::unprocessable('not_stock_tracked', 'This product is always available.');
        }

        if (Quantity::isPositive($available)) {
            throw ApiException::unprocessable('item_in_stock', 'This item is in stock: you can buy it now.');
        }

        $email = strtolower(trim($email));
        $row = new BackInStockSubscription;
        $row->forceFill([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'customer_id' => $customer?->id,
            'email' => $email,
        ]);

        try {
            $row->save();
        } catch (QueryException $e) {
            // Already waiting for this item: a repeat request is a no-op.
            $existing = $this->pending($product->id, $variant?->id)->where('email', $email)->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        return $row->refresh();
    }

    /**
     * Every waiting subscription of the item (and of the bundles it
     * completes) receives back_in_stock.available once: notified_at is
     * stamped under row locks before the alerts are sent after commit.
     * Stock is checked again: it may have sold out since the event.
     *
     * @return int the alerts sent
     */
    public function checkAndNotify(Product $product, ?ProductVariant $variant = null): int
    {
        $sent = 0;

        if (Quantity::isPositive((string) ($this->inventory->getAvailableStock($product, $variant) ?? '0'))) {
            // A restocked variant also answers requests for the product as a whole.
            $sent += $this->notifyPending($product, $variant === null ? [null] : [$variant->id, null]);
        }

        $bundleIds = ProductBundleItem::query()->where('child_product_id', $product->id)
            ->when($variant !== null, static fn ($q) => $q->where(static fn ($w) => $w->whereNull('child_product_variant_id')->orWhere('child_product_variant_id', $variant->id)))
            ->distinct()->pluck('bundle_product_id');

        Product::query()->whereKey($bundleIds)->whereIn('id', BackInStockSubscription::query()->whereNull('notified_at')->select('product_id'))
            ->get()->each(function (Product $bundle) use (&$sent): void {
                if (Quantity::isPositive((string) ($this->inventory->getAvailableStock($bundle) ?? '0'))) {
                    $sent += $this->notifyPending($bundle, [null]);
                }
            });

        return $sent;
    }

    /**
     * The waiting list of a product for staff (§56), by variant.
     *
     * @return Collection<int, BackInStockSubscription>
     */
    public function listPendingForProduct(Product $product): Collection
    {
        return BackInStockSubscription::query()->with(['variant:id,sku', 'customer:id,name'])
            ->where('product_id', $product->id)->whereNull('notified_at')
            ->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * The personal-data eraser (§26.4): the customer's requests, and guest
     * requests made with the same address.
     */
    public function eraseForCustomer(Customer $customer): void
    {
        BackInStockSubscription::query()->where(static fn ($q) => $q->where('customer_id', $customer->id)->orWhere('email', strtolower((string) $customer->email)))->delete();
    }

    /**
     * @param  list<int|null>  $variantIds  null = the product-level requests
     */
    private function notifyPending(Product $product, array $variantIds): int
    {
        $rows = DB::connection('tenant')->transaction(function () use ($product, $variantIds): Collection {
            $rows = BackInStockSubscription::query()->with(['customer', 'variant:id,sku'])
                ->where('product_id', $product->id)->whereNull('notified_at')
                ->where(static function ($q) use ($variantIds): void {
                    $ids = array_values(array_filter($variantIds, static fn (?int $id): bool => $id !== null));
                    $q->whereIn('product_variant_id', $ids === [] ? [0] : $ids);

                    if (in_array(null, $variantIds, true)) {
                        $q->orWhereNull('product_variant_id');
                    }
                })
                ->lockForUpdate()->get();

            if ($rows->isNotEmpty()) {
                BackInStockSubscription::query()->whereKey($rows->modelKeys())->update(['notified_at' => now()]);
            }

            return $rows;
        });

        $tenant = tenant();
        $storeName = (string) $this->settings->get('store_name', '');
        $url = $tenant instanceof Tenant ? FrontendUrl::storefront($tenant, '/products/'.($product->slug ?? $product->id)) : '';

        foreach ($rows as $row) {
            $customer = $row->customer;
            $recipient = $customer !== null && $customer->anonymized_at === null && $customer->is_active
                ? $customer : Notification::route('mail', $row->email);

            $this->notifications->dispatch('back_in_stock.available', $recipient, [
                'product_name' => $row->variant === null ? $product->name : $product->name.' ('.$row->variant->sku.')',
                'store_name' => $storeName,
                'product_url' => $url,
            ]);
        }

        return $rows->count();
    }

    /**
     * @return Builder<BackInStockSubscription>
     */
    private function pending(int $productId, ?int $variantId): Builder
    {
        return BackInStockSubscription::query()->where('product_id', $productId)->whereNull('notified_at')
            ->when($variantId === null, static fn ($q) => $q->whereNull('product_variant_id'), static fn ($q) => $q->where('product_variant_id', $variantId));
    }
}
