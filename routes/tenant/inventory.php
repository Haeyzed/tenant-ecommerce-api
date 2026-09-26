<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\Tenant\Admin\InventoryController;
use App\Modules\Inventory\Http\Controllers\Tenant\Admin\ProductInventoryController;
use App\Modules\Inventory\Http\Controllers\Tenant\Admin\StockAdjustmentController;
use App\Modules\Inventory\Http\Controllers\Tenant\Admin\StockAdjustmentItemController;
use App\Modules\Inventory\Http\Controllers\Tenant\Admin\StockTransferController;
use App\Modules\Inventory\Http\Controllers\Tenant\Admin\WarehouseController;
use App\Modules\Inventory\Http\Controllers\Tenant\Admin\WarehouseInventoryController;
use App\Modules\Inventory\Http\Controllers\Tenant\Admin\WarehousePriceController;
use Illuminate\Support\Facades\Route;

/*
| Warehouses, inventory, transfers, adjustments and per-warehouse prices
| (spec §32.10, §33.3, §34). Core commerce: no feature key. There are no
| customer-facing warehouse routes (§32.1).
*/

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.inventory.admin.')->group(function (): void {
    Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('usage.limit:max_warehouses')->name('warehouses.store');
    Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])->whereNumber('warehouse')->name('warehouses.update');
    Route::post('warehouses/{warehouse}/deactivate', [WarehouseController::class, 'deactivate'])->whereNumber('warehouse')->name('warehouses.deactivate');
    Route::post('warehouses/{warehouse}/activate', [WarehouseController::class, 'activate'])->whereNumber('warehouse')->middleware('usage.limit:max_warehouses')->name('warehouses.activate');
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->whereNumber('warehouse')->name('warehouses.destroy');
    Route::get('warehouses/{warehouse}/inventory', [WarehouseInventoryController::class, 'index'])->whereNumber('warehouse')->name('warehouses.inventory');
    Route::get('warehouses/{warehouse}/product-lookup', [StockAdjustmentController::class, 'productLookup'])->whereNumber('warehouse')->name('warehouses.product-lookup');

    Route::get('products/{product}/inventory', [ProductInventoryController::class, 'index'])->whereNumber('product')->name('products.inventory');
    Route::get('products/{product}/warehouse-prices', [WarehousePriceController::class, 'index'])->whereNumber('product')->name('products.warehouse-prices.index');
    Route::post('products/{product}/warehouse-prices', [WarehousePriceController::class, 'store'])->whereNumber('product')->name('products.warehouse-prices.store');
    Route::patch('products/{product}/warehouse-prices/{price}', [WarehousePriceController::class, 'update'])->whereNumber(['product', 'price'])->name('products.warehouse-prices.update');
    Route::delete('products/{product}/warehouse-prices/{price}', [WarehousePriceController::class, 'destroy'])->whereNumber(['product', 'price'])->name('products.warehouse-prices.destroy');

    Route::get('inventory', [InventoryController::class, 'index'])->name('index');
    Route::get('inventory/low-stock', [InventoryController::class, 'lowStock'])->name('low-stock');
    Route::get('inventory/out-of-stock', [InventoryController::class, 'outOfStock'])->name('out-of-stock');
    Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('movements');

    Route::get('stock-transfers', [StockTransferController::class, 'index'])->name('transfers.index');
    Route::post('stock-transfers', [StockTransferController::class, 'store'])->name('transfers.store');
    Route::get('stock-transfers/{transfer}', [StockTransferController::class, 'show'])->whereNumber('transfer')->name('transfers.show');
    Route::patch('stock-transfers/{transfer}', [StockTransferController::class, 'update'])->whereNumber('transfer')->name('transfers.update');
    Route::patch('stock-transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->whereNumber('transfer')->name('transfers.dispatch');
    Route::patch('stock-transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->whereNumber('transfer')->name('transfers.receive');
    Route::patch('stock-transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->whereNumber('transfer')->name('transfers.cancel');

    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->name('adjustments.index');
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('usage.limit:max_storage_mb')->name('adjustments.store');
    Route::get('stock-adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])->whereNumber('adjustment')->name('adjustments.show');
    Route::post('stock-adjustments/{adjustment}/items', [StockAdjustmentItemController::class, 'store'])->whereNumber('adjustment')->name('adjustments.items.store');
    Route::patch('stock-adjustments/{adjustment}/items/{item}', [StockAdjustmentItemController::class, 'update'])->whereNumber(['adjustment', 'item'])->name('adjustments.items.update');
    Route::delete('stock-adjustments/{adjustment}/items/{item}', [StockAdjustmentItemController::class, 'destroy'])->whereNumber(['adjustment', 'item'])->name('adjustments.items.destroy');
    Route::patch('stock-adjustments/{adjustment}/submit', [StockAdjustmentController::class, 'submit'])->whereNumber('adjustment')->name('adjustments.submit');
});
