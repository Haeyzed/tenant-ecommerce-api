<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Tenant\SellerAuthController;
use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Admin\SellerController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Admin\SellerGroupController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Admin\SellerLedgerController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Admin\SellerPayoutController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Admin\SellerProductModerationController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Seller\LedgerController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Seller\OrderController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Seller\ProductContentController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Seller\ProductController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Seller\ProductQuestionController;
use App\Modules\Marketplace\Http\Controllers\Tenant\Seller\ProfileController;
use Illuminate\Support\Facades\Route;

/*
| The marketplace (spec §10.5 seller authentication, §50.7). Feature
| `marketplace`. Sellers reach only their own records. Generating and
| paying out earned payouts stay available while the module winds down.
*/

Route::middleware(['tenant.public', 'feature:marketplace'])->prefix('seller/auth')->name('tenant.auth.seller.')->group(function (): void {
    Route::middleware('throttle:auth-sensitive')->group(function (): void {
        Route::post('register', [SellerAuthController::class, 'register'])->name('register');
        Route::post('login', [SellerAuthController::class, 'login'])->name('login');
        Route::post('password/forgot', [SellerAuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('password/reset', [SellerAuthController::class, 'resetPassword'])->name('password.reset');
        Route::patch('password', [SellerAuthController::class, 'changePassword'])->middleware('auth.as:seller')->name('password.change');
    });

    Route::post('logout', [SellerAuthController::class, 'logout'])->middleware(['auth.as:seller', 'throttle:api'])->name('logout');
});

Route::middleware(['tenant.seller', 'feature:marketplace'])->prefix('seller')->name('tenant.seller.')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::post('products', [ProductController::class, 'store'])->middleware('usage.limit:max_products')->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
    Route::patch('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');

    // The parts of the seller's own products (§50.3), as staff manage them (§27.5).
    Route::prefix('products/{product}')->whereNumber('product')->name('products.')->group(function (): void {
        Route::get('variants', [ProductContentController::class, 'variants'])->name('variants.index');
        Route::post('variants', [ProductContentController::class, 'storeVariant'])->name('variants.store');
        Route::patch('variants/{variant}', [ProductContentController::class, 'updateVariant'])->whereNumber('variant')->name('variants.update');
        Route::delete('variants/{variant}', [ProductContentController::class, 'destroyVariant'])->whereNumber('variant')->name('variants.destroy');

        Route::post('media', [ProductContentController::class, 'storeMedia'])->middleware('usage.limit:max_storage_mb')->name('media.store');
        Route::post('media/reorder', [ProductContentController::class, 'reorderMedia'])->name('media.reorder');
        Route::delete('media/{media}', [ProductContentController::class, 'destroyMedia'])->whereNumber('media')->name('media.destroy');
        Route::post('media/{media}/featured', [ProductContentController::class, 'featuredMedia'])->whereNumber('media')->name('media.featured');

        Route::post('digital-files', [ProductContentController::class, 'storeDigitalFile'])->middleware('usage.limit:max_storage_mb')->name('digital-files.store');
        Route::delete('digital-files/{file}', [ProductContentController::class, 'destroyDigitalFile'])->whereNumber('file')->name('digital-files.destroy');

        Route::post('bundle-items', [ProductContentController::class, 'storeBundleItem'])->name('bundle-items.store');
        Route::delete('bundle-items/{item}', [ProductContentController::class, 'destroyBundleItem'])->whereNumber('item')->name('bundle-items.destroy');

        Route::put('specifications', [ProductContentController::class, 'specifications'])->name('specifications');
    });

    Route::get('product-options', [ProductContentController::class, 'options'])->name('product-options.index');
    Route::get('units', [ProductContentController::class, 'units'])->name('units.index');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::get('payouts', [LedgerController::class, 'payouts'])->name('payouts.index');
    Route::post('product-questions/{question}/answers', [ProductQuestionController::class, 'answer'])->whereNumber('question')->name('product-questions.answers');
});

Route::middleware(['tenant.admin', 'feature:marketplace', 'module.notice:marketplace'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('sellers', [SellerController::class, 'index'])->name('sellers.index');
    Route::get('sellers/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'sellers')->name('sellers.metrics');
    Route::prefix('sellers/{seller}')->whereNumber('seller')->name('sellers.')->group(function (): void {
        Route::get('/', [SellerController::class, 'show'])->name('show');
        Route::post('approve', [SellerController::class, 'approve'])->middleware('usage.limit:max_sellers')->name('approve');
        Route::post('reject', [SellerController::class, 'reject'])->name('reject');
        Route::post('suspend', [SellerController::class, 'suspend'])->name('suspend');
        Route::patch('commission-rate', [SellerController::class, 'commissionRate'])->name('commission-rate');
        Route::post('assign-group', [SellerController::class, 'assignGroup'])->name('assign-group');
        Route::get('ledger', [SellerLedgerController::class, 'index'])->name('ledger.index');
        Route::get('payouts', [SellerPayoutController::class, 'index'])->name('payouts.index');
        Route::post('payouts', [SellerPayoutController::class, 'store'])->name('payouts.store');
        Route::post('payouts/{payout}/mark-paid', [SellerPayoutController::class, 'markPaid'])->whereNumber('payout')->middleware('idempotency')->name('payouts.mark-paid');
    });

    Route::get('seller-products', [SellerProductModerationController::class, 'index'])->name('seller-products.index');
    Route::post('seller-products/{product}/approve', [SellerProductModerationController::class, 'approve'])->whereNumber('product')->name('seller-products.approve');
    Route::post('seller-products/{product}/reject', [SellerProductModerationController::class, 'reject'])->whereNumber('product')->name('seller-products.reject');

    Route::get('seller-groups', [SellerGroupController::class, 'index'])->name('seller-groups.index');
    Route::post('seller-groups', [SellerGroupController::class, 'store'])->name('seller-groups.store');
    Route::patch('seller-groups/{group}', [SellerGroupController::class, 'update'])->whereNumber('group')->name('seller-groups.update');
    Route::delete('seller-groups/{group}', [SellerGroupController::class, 'destroy'])->whereNumber('group')->name('seller-groups.destroy');
});
