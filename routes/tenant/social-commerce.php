<?php

declare(strict_types=1);

use App\Modules\Integrations\SocialCommerce\Http\Controllers\Tenant\Admin\AccountController;
use App\Modules\Integrations\SocialCommerce\Http\Controllers\Tenant\Admin\SyncController;
use Illuminate\Support\Facades\Route;

/*
| Social commerce (spec §69.3). Feature `social_commerce`.
*/

Route::middleware(['tenant.admin', 'feature:social_commerce', 'module.notice:social_commerce'])->prefix('admin/social-commerce')->name('tenant.admin.social-commerce.')->group(function (): void {
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->whereNumber('account')->name('accounts.destroy');
    Route::post('accounts/{account}/test-connection', [AccountController::class, 'testConnection'])->whereNumber('account')->name('accounts.test-connection');

    Route::post('accounts/{account}/sync/products', [SyncController::class, 'products'])->whereNumber('account')->name('accounts.sync.products');
    Route::post('accounts/{account}/sync/orders', [SyncController::class, 'orders'])->whereNumber('account')->name('accounts.sync.orders');
    Route::post('accounts/{account}/products/{product}', [SyncController::class, 'pushProduct'])->whereNumber(['account', 'product'])->name('accounts.products.push');
    Route::delete('accounts/{account}/products/{product}', [SyncController::class, 'removeProduct'])->whereNumber(['account', 'product'])->name('accounts.products.remove');
    Route::get('sync/logs', [SyncController::class, 'logs'])->name('sync.logs');
    Route::get('sync/metrics', [SyncController::class, 'metrics'])->name('sync.metrics');
});
