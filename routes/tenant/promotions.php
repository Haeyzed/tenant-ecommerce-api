<?php

declare(strict_types=1);

use App\Modules\Promotions\Http\Controllers\Tenant\Admin\CouponController;
use App\Modules\Promotions\Http\Controllers\Tenant\Admin\FlashSaleController as AdminFlashSaleController;
use App\Modules\Promotions\Http\Controllers\Tenant\Admin\FlashSaleProductController;
use App\Modules\Promotions\Http\Controllers\Tenant\Admin\PromotionController;
use App\Modules\Promotions\Http\Controllers\Tenant\Admin\PromotionRedemptionController;
use App\Modules\Promotions\Http\Controllers\Tenant\Admin\PromotionTargetController;
use App\Modules\Promotions\Http\Controllers\Tenant\FlashSaleController;
use Illuminate\Support\Facades\Route;

/*
| Promotions, coupons and flash sales (spec §37.9). Core commerce. Coupons
| reach customers through the cart (§38.7).
*/

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.promotions.admin.')->group(function (): void {
    Route::get('promotions', [PromotionController::class, 'index'])->name('index');
    Route::post('promotions', [PromotionController::class, 'store'])->name('store');
    Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->whereNumber('promotion')->name('show');
    Route::patch('promotions/{promotion}', [PromotionController::class, 'update'])->whereNumber('promotion')->name('update');
    Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->whereNumber('promotion')->name('destroy');
    Route::post('promotions/{promotion}/duplicate', [PromotionController::class, 'duplicate'])->whereNumber('promotion')->name('duplicate');
    Route::put('promotions/{promotion}/targets', [PromotionTargetController::class, 'sync'])->whereNumber('promotion')->name('targets');
    Route::get('promotions/{promotion}/redemptions', [PromotionRedemptionController::class, 'index'])->whereNumber('promotion')->name('redemptions');

    Route::get('promotions/{promotion}/coupons', [CouponController::class, 'index'])->whereNumber('promotion')->name('coupons.index');
    Route::post('promotions/{promotion}/coupons', [CouponController::class, 'store'])->whereNumber('promotion')->name('coupons.store');
    Route::post('promotions/{promotion}/coupons/generate', [CouponController::class, 'generate'])->whereNumber('promotion')->name('coupons.generate');
    Route::post('coupons/bulk', [CouponController::class, 'bulk'])->name('coupons.bulk');
    Route::patch('coupons/{coupon}', [CouponController::class, 'update'])->whereNumber('coupon')->name('coupons.update');
    Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->whereNumber('coupon')->name('coupons.destroy');

    Route::get('flash-sales', [AdminFlashSaleController::class, 'index'])->name('flash-sales.index');
    Route::post('flash-sales', [AdminFlashSaleController::class, 'store'])->name('flash-sales.store');
    Route::patch('flash-sales/{sale}', [AdminFlashSaleController::class, 'update'])->whereNumber('sale')->name('flash-sales.update');
    Route::delete('flash-sales/{sale}', [AdminFlashSaleController::class, 'destroy'])->whereNumber('sale')->name('flash-sales.destroy');
    Route::post('flash-sales/{sale}/products', [FlashSaleProductController::class, 'store'])->whereNumber('sale')->name('flash-sales.products.store');
    Route::delete('flash-sales/{sale}/products/{product}', [FlashSaleProductController::class, 'destroy'])->whereNumber(['sale', 'product'])->name('flash-sales.products.destroy');
});

Route::middleware(['tenant.public', 'module.notice:core'])->name('tenant.promotions.')->group(function (): void {
    Route::get('flash-sales', [FlashSaleController::class, 'index'])->name('flash-sales.index');
});
