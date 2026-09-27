<?php

declare(strict_types=1);

namespace App\Modules\Purchasing;

use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\WarehouseUsage;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\QuotationRequest;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires purchasing into the shared registries: custom fields for suppliers
 * and purchase orders (§23.1), and the records that keep a warehouse from
 * being deleted (§32.9).
 */
final class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register(SupplierService::ENTITY, Supplier::class, 'suppliers');
            $registry->register(PurchaseOrderService::ENTITY, PurchaseOrder::class, 'purchase-orders');
        });

        $this->app->afterResolving(WarehouseUsage::class, static function (WarehouseUsage $usage): void {
            $usage->register('purchase_orders', static fn (Warehouse $w): bool => PurchaseOrder::query()->where('warehouse_id', $w->id)->exists());
            $usage->register('quotation_requests', static fn (Warehouse $w): bool => QuotationRequest::query()->where('warehouse_id', $w->id)->exists());
            $usage->register('purchase_returns', static fn (Warehouse $w): bool => PurchaseReturn::query()->where('warehouse_id', $w->id)->exists());
        });
    }
}
