<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\GiftCards\Http\Controllers\Tenant\Admin\GiftCardController as AdminGiftCardController;
use App\Modules\GiftCards\Http\Controllers\Tenant\CartGiftCardController;
use App\Modules\GiftCards\Http\Controllers\Tenant\GiftCardController;
use Illuminate\Support\Facades\Route;

/*
| Gift cards (spec §46.5, cart routes §38.7). Feature `gift_cards`. The cart
| routes and the balance check stay available while the module winds down,
| so shoppers can still use and check cards they hold.
*/

Route::middleware(['tenant.storefront', 'feature:gift_cards'])->name('tenant.storefront.')->group(function (): void {
    Route::post('cart/apply-gift-card', [CartGiftCardController::class, 'store'])->name('cart.gift-card.store');
    Route::delete('cart/gift-card', [CartGiftCardController::class, 'destroy'])->name('cart.gift-card.destroy');
    Route::post('gift-cards/purchase', [GiftCardController::class, 'purchase'])->middleware('idempotency')->name('gift-cards.purchase');
});

Route::middleware(['tenant.public', 'feature:gift_cards', 'throttle:auth-sensitive'])->name('tenant.public.')->group(function (): void {
    Route::get('gift-cards/{code}/balance', [GiftCardController::class, 'balance'])->where('code', '[A-Za-z0-9]{8,32}')->name('gift-cards.balance');
});

Route::middleware(['tenant.admin', 'feature:gift_cards', 'module.notice:gift_cards'])->prefix('admin')->name('tenant.gift-cards.')->group(function (): void {
    Route::get('gift-cards', [AdminGiftCardController::class, 'index'])->name('index');
    Route::get('gift-cards/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'gift-cards')->name('metrics');
    Route::post('gift-cards', [AdminGiftCardController::class, 'store'])->middleware('idempotency')->name('store');
    Route::get('gift-cards/{card}', [AdminGiftCardController::class, 'show'])->whereNumber('card')->name('show');
    Route::patch('gift-cards/{card}/disable', [AdminGiftCardController::class, 'disable'])->whereNumber('card')->name('disable');
});
