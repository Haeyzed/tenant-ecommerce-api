<?php

declare(strict_types=1);

use App\Modules\Integrations\WooCommerce\Http\Controllers\Tenant\Admin\SettingsController;
use App\Modules\Integrations\WooCommerce\Http\Controllers\Tenant\Admin\SyncController;
use Illuminate\Support\Facades\Route;

/*
| WooCommerce integration (spec §68.4). Feature `woocommerce`.
*/

Route::middleware(['tenant.admin', 'feature:woocommerce', 'module.notice:woocommerce'])->prefix('admin/woocommerce')->name('tenant.admin.woocommerce.')->group(function (): void {
    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/test-connection', [SettingsController::class, 'testConnection'])->name('settings.test-connection');

    Route::post('sync/categories', [SyncController::class, 'categories'])->name('sync.categories');
    Route::post('sync/products', [SyncController::class, 'products'])->name('sync.products');
    Route::post('sync/tax-rates', [SyncController::class, 'taxRates'])->name('sync.tax-rates');
    Route::post('sync/orders', [SyncController::class, 'orders'])->name('sync.orders');
    Route::post('products/{product}/push', [SyncController::class, 'pushProduct'])->whereNumber('product')->name('products.push');
    Route::get('sync/logs', [SyncController::class, 'logs'])->name('sync.logs');
    Route::get('sync/metrics', [SyncController::class, 'metrics'])->name('sync.metrics');
});
