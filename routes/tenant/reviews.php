<?php

declare(strict_types=1);

use App\Modules\Reviews\Http\Controllers\Tenant\Admin\ReviewController as AdminReviewController;
use App\Modules\Reviews\Http\Controllers\Tenant\ReviewController;
use App\Modules\Wishlist\Http\Controllers\Tenant\WishlistController;
use Illuminate\Support\Facades\Route;

/*
| Reviews and wishlist (spec §42.3). Core commerce.
*/

$slug = '[A-Za-z0-9-]+';

Route::middleware(['tenant.public', 'module.notice:core'])->name('tenant.reviews.')->group(function () use ($slug): void {
    Route::get('products/{product}/reviews', [ReviewController::class, 'index'])->where('product', $slug)->name('index');
});

Route::middleware(['tenant.customer', 'module.notice:core'])->name('tenant.')->group(function () use ($slug): void {
    Route::post('products/{product}/reviews', [ReviewController::class, 'store'])->where('product', $slug)->name('reviews.store');

    Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::delete('wishlist/{product}', [WishlistController::class, 'destroy'])->whereNumber('product')->name('wishlist.destroy');
    Route::post('wishlist/{product}/move-to-cart', [WishlistController::class, 'moveToCart'])->whereNumber('product')->name('wishlist.move-to-cart');
});

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.reviews.admin.')->group(function (): void {
    Route::get('reviews', [AdminReviewController::class, 'index'])->name('index');
    Route::post('reviews/{review}/approve', [AdminReviewController::class, 'approve'])->whereNumber('review')->name('approve');
    Route::post('reviews/{review}/reject', [AdminReviewController::class, 'reject'])->whereNumber('review')->name('reject');
    Route::delete('reviews/{review}', [AdminReviewController::class, 'destroy'])->whereNumber('review')->name('destroy');
});
