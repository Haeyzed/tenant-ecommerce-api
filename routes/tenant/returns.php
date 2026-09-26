<?php

declare(strict_types=1);

use App\Modules\Returns\Http\Controllers\Tenant\Admin\ReturnController as AdminReturnController;
use App\Modules\Returns\Http\Controllers\Tenant\Admin\ReturnReasonController;
use App\Modules\Returns\Http\Controllers\Tenant\ReturnController;
use Illuminate\Support\Facades\Route;

/*
| Returns and exchanges (spec §41.7). Core commerce.
*/

Route::middleware(['tenant.storefront', 'module.notice:core'])->name('tenant.returns.')->group(function (): void {
    Route::get('orders/{order}/returns', [ReturnController::class, 'indexForOrder'])->whereNumber('order')->name('for-order');
    Route::post('orders/{order}/returns', [ReturnController::class, 'store'])->whereNumber('order')->name('store');
    Route::get('returns', [ReturnController::class, 'index'])->name('index');
    Route::get('returns/{return}', [ReturnController::class, 'show'])->whereNumber('return')->name('show');
});

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.returns.admin.')->group(function (): void {
    Route::get('returns', [AdminReturnController::class, 'index'])->name('index');
    Route::get('returns/{return}', [AdminReturnController::class, 'show'])->whereNumber('return')->name('show');
    Route::patch('returns/{return}/approve', [AdminReturnController::class, 'approve'])->whereNumber('return')->name('approve');
    Route::patch('returns/{return}/reject', [AdminReturnController::class, 'reject'])->whereNumber('return')->name('reject');
    Route::patch('returns/{return}/receive', [AdminReturnController::class, 'receive'])->whereNumber('return')->name('receive');
    Route::post('returns/{return}/refund', [AdminReturnController::class, 'refund'])->whereNumber('return')->middleware('idempotency')->name('refund');
    Route::post('returns/{return}/exchange', [AdminReturnController::class, 'exchange'])->whereNumber('return')->name('exchange');
    Route::patch('returns/{return}/close', [AdminReturnController::class, 'close'])->whereNumber('return')->name('close');

    Route::get('return-reasons', [ReturnReasonController::class, 'index'])->name('reasons.index');
    Route::post('return-reasons', [ReturnReasonController::class, 'store'])->name('reasons.store');
    Route::patch('return-reasons/{reason}', [ReturnReasonController::class, 'update'])->whereNumber('reason')->name('reasons.update');
    Route::delete('return-reasons/{reason}', [ReturnReasonController::class, 'destroy'])->whereNumber('reason')->name('reasons.destroy');
});
