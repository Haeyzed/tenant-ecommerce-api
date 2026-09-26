<?php

declare(strict_types=1);

use App\Modules\Cart\Http\Controllers\Tenant\CartController;
use App\Modules\Cart\Http\Controllers\Tenant\CartCouponController;
use App\Modules\Cart\Http\Controllers\Tenant\CartItemController;
use Illuminate\Support\Facades\Route;

/*
| The cart (spec §38.7): an authenticated customer, or a guest carrying
| X-Guest-Token. Gift-card, reward-point and currency routes arrive with
| those modules; placing orders arrives with orders.
*/

Route::middleware(['tenant.storefront', 'module.notice:core'])->prefix('cart')->name('tenant.cart.')->group(function (): void {
    Route::get('/', [CartController::class, 'show'])->name('show');
    Route::post('items', [CartItemController::class, 'store'])->name('items.store');
    Route::patch('items/{item}', [CartItemController::class, 'update'])->whereNumber('item')->name('items.update');
    Route::delete('items/{item}', [CartItemController::class, 'destroy'])->whereNumber('item')->name('items.destroy');
    Route::post('apply-coupon', [CartCouponController::class, 'store'])->name('coupon.store');
    Route::delete('coupon', [CartCouponController::class, 'destroy'])->name('coupon.destroy');
});
