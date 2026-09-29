<?php

declare(strict_types=1);

use App\Modules\Restaurant\Http\Controllers\Tenant\Admin\FloorController;
use App\Modules\Restaurant\Http\Controllers\Tenant\Admin\KitchenController;
use App\Modules\Restaurant\Http\Controllers\Tenant\Admin\ModifierGroupController;
use App\Modules\Restaurant\Http\Controllers\Tenant\Admin\ModifierOptionController;
use App\Modules\Restaurant\Http\Controllers\Tenant\Admin\ReservationController;
use App\Modules\Restaurant\Http\Controllers\Tenant\Admin\TableController;
use App\Modules\Restaurant\Http\Controllers\Tenant\Admin\TableOrderController;
use Illuminate\Support\Facades\Route;

/*
| Restaurant (spec §65.5). Feature `restaurant` (requires `pos`). Settling
| and voiding stay open while the module winds down, so open tables close.
*/

Route::middleware(['tenant.admin', 'feature:restaurant', 'module.notice:restaurant'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::prefix('restaurant')->name('restaurant.')->group(function (): void {
        Route::get('floors', [FloorController::class, 'index'])->name('floors.index');
        Route::post('floors', [FloorController::class, 'store'])->name('floors.store');
        Route::patch('floors/{floor}', [FloorController::class, 'update'])->whereNumber('floor')->name('floors.update');
        Route::delete('floors/{floor}', [FloorController::class, 'destroy'])->whereNumber('floor')->name('floors.destroy');

        Route::get('tables', [TableController::class, 'index'])->name('tables.index');
        Route::post('tables', [TableController::class, 'store'])->name('tables.store');
        Route::patch('tables/{table}', [TableController::class, 'update'])->whereNumber('table')->name('tables.update');
        Route::delete('tables/{table}', [TableController::class, 'destroy'])->whereNumber('table')->name('tables.destroy');
        Route::patch('tables/{table}/status', [TableController::class, 'updateStatus'])->whereNumber('table')->name('tables.status');
        Route::get('tables/{table}/current-order', [TableOrderController::class, 'current'])->whereNumber('table')->name('tables.current-order');
        Route::post('tables/{table}/orders', [TableOrderController::class, 'store'])->whereNumber('table')->middleware('usage.limit:max_orders_per_month')->name('tables.orders.store');

        Route::post('table-orders/{order}/items', [TableOrderController::class, 'addItems'])->whereNumber('order')->name('table-orders.items');
        Route::post('table-orders/{order}/settle', [TableOrderController::class, 'settle'])->whereNumber('order')->name('table-orders.settle');
        Route::post('table-orders/{order}/void', [TableOrderController::class, 'void'])->whereNumber('order')->name('table-orders.void');

        Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
        Route::patch('reservations/{reservation}', [ReservationController::class, 'update'])->whereNumber('reservation')->name('reservations.update');
        Route::patch('reservations/{reservation}/seat', [ReservationController::class, 'seat'])->whereNumber('reservation')->name('reservations.seat');
        Route::patch('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->whereNumber('reservation')->name('reservations.cancel');
        Route::patch('reservations/{reservation}/no-show', [ReservationController::class, 'noShow'])->whereNumber('reservation')->name('reservations.no-show');

        Route::get('modifier-groups', [ModifierGroupController::class, 'index'])->name('modifier-groups.index');
        Route::post('modifier-groups', [ModifierGroupController::class, 'store'])->name('modifier-groups.store');
        Route::patch('modifier-groups/{group}', [ModifierGroupController::class, 'update'])->whereNumber('group')->name('modifier-groups.update');
        Route::delete('modifier-groups/{group}', [ModifierGroupController::class, 'destroy'])->whereNumber('group')->name('modifier-groups.destroy');
        Route::post('modifier-groups/{group}/options', [ModifierOptionController::class, 'store'])->whereNumber('group')->name('modifier-groups.options.store');
        Route::patch('modifier-groups/{group}/options/{option}', [ModifierOptionController::class, 'update'])->whereNumber(['group', 'option'])->name('modifier-groups.options.update');
        Route::delete('modifier-groups/{group}/options/{option}', [ModifierOptionController::class, 'destroy'])->whereNumber(['group', 'option'])->name('modifier-groups.options.destroy');

        Route::get('kitchen/tickets', [KitchenController::class, 'tickets'])->name('kitchen.tickets');
        Route::patch('kitchen/order-items/{item}/status', [KitchenController::class, 'updateItemStatus'])->whereNumber('item')->name('kitchen.order-items.status');
        Route::get('kitchen/metrics', [KitchenController::class, 'metrics'])->name('kitchen.metrics');
    });

    Route::post('products/{product}/modifier-groups/{group}', [ModifierGroupController::class, 'attach'])->whereNumber(['product', 'group'])->name('products.modifier-groups.attach');
    Route::delete('products/{product}/modifier-groups/{group}', [ModifierGroupController::class, 'detach'])->whereNumber(['product', 'group'])->name('products.modifier-groups.detach');
});
