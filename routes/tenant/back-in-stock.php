<?php

declare(strict_types=1);

use App\Modules\BackInStock\Http\Controllers\Tenant\Admin\AlertController as AdminAlertController;
use App\Modules\BackInStock\Http\Controllers\Tenant\AlertController;
use Illuminate\Support\Facades\Route;

/*
| Back-in-stock alerts (spec §56). Feature `back_in_stock_alerts`. The
| storefront request needs no sign-in; it is rate-limited per IP.
*/

Route::middleware(['tenant.storefront', 'feature:back_in_stock_alerts', 'throttle:auth-sensitive'])->name('tenant.storefront.')->group(function (): void {
    Route::post('products/{product}/back-in-stock-alert', [AlertController::class, 'store'])->where('product', '[A-Za-z0-9-]+')->name('products.back-in-stock-alert.store');
});

Route::middleware(['tenant.admin', 'feature:back_in_stock_alerts', 'module.notice:back_in_stock_alerts'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('products/{product}/back-in-stock-subscribers', [AdminAlertController::class, 'index'])->whereNumber('product')->name('products.back-in-stock-subscribers.index');
});
