<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Http\Controllers\Tenant\Admin\BillOfMaterialController;
use App\Modules\Manufacturing\Http\Controllers\Tenant\Admin\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
| Manufacturing (spec §64.4). Feature `manufacturing`.
*/

Route::middleware(['tenant.admin', 'feature:manufacturing', 'module.notice:manufacturing'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('products/{product}/bill-of-materials', [BillOfMaterialController::class, 'index'])->whereNumber('product')->name('products.bill-of-materials.index');
    Route::post('products/{product}/bill-of-materials', [BillOfMaterialController::class, 'store'])->whereNumber('product')->name('products.bill-of-materials.store');
    Route::get('bill-of-materials/{bom}', [BillOfMaterialController::class, 'show'])->whereNumber('bom')->name('bill-of-materials.show');
    Route::patch('bill-of-materials/{bom}', [BillOfMaterialController::class, 'update'])->whereNumber('bom')->name('bill-of-materials.update');
    Route::delete('bill-of-materials/{bom}', [BillOfMaterialController::class, 'destroy'])->whereNumber('bom')->name('bill-of-materials.destroy');
    Route::patch('bill-of-materials/{bom}/set-default', [BillOfMaterialController::class, 'setDefault'])->whereNumber('bom')->name('bill-of-materials.set-default');

    Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
    Route::get('work-orders/{order}', [WorkOrderController::class, 'show'])->whereNumber('order')->name('work-orders.show');
    Route::patch('work-orders/{order}/start', [WorkOrderController::class, 'start'])->whereNumber('order')->name('work-orders.start');
    Route::patch('work-orders/{order}/complete', [WorkOrderController::class, 'complete'])->whereNumber('order')->name('work-orders.complete');
    Route::patch('work-orders/{order}/cancel', [WorkOrderController::class, 'cancel'])->whereNumber('order')->name('work-orders.cancel');
});
