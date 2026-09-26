<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Catalog\Support\ProductAvailability;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Inventory\Support\WarehouseUsage;
use App\Modules\Users\Models\User;
use App\Modules\Users\Support\StaffAccessScope;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

/**
 * Wires inventory into its extension points: storefront availability
 * (§31.1), the warehouse staff scope (§25.3), the max_warehouses counter
 * (§11.10) and the records that block a warehouse's deletion (§32.9).
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WarehouseUsage::class);

        $this->app->afterResolving(ProductAvailability::class, function (ProductAvailability $availability): void {
            $availability->useInventory(
                fn (Collection $products): array => $this->app->make(InventoryService::class)->inStockMap($products),
                fn (Builder $query) => $this->app->make(InventoryService::class)->applyInStockConstraint($query),
            );
        });

        $this->app->afterResolving(StaffAccessScope::class, function (StaffAccessScope $scope): void {
            $scope->resolveWarehousesWith(fn (User $user): array => $this->app->make(WarehouseService::class)->userWarehouseIds($user));
        });

        $this->app->afterResolving(UsageCounterRegistry::class, static function (UsageCounterRegistry $registry): void {
            $registry->register('max_warehouses', static fn (): int => Warehouse::query()->where('is_active', true)->count());
        });

        $this->app->afterResolving(WarehouseUsage::class, static function (WarehouseUsage $usage): void {
            $usage->register('stock_history', static fn (Warehouse $w): bool => InventoryMovement::query()->where('warehouse_id', $w->id)->exists());
            $usage->register('stock_transfers', static fn (Warehouse $w): bool => StockTransfer::query()->where('from_warehouse_id', $w->id)->orWhere('to_warehouse_id', $w->id)->exists());
            $usage->register('stock_adjustments', static fn (Warehouse $w): bool => StockAdjustment::query()->where('warehouse_id', $w->id)->exists());
        });
    }
}
