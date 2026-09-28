<?php

declare(strict_types=1);

use App\Modules\RewardPoints\Http\Controllers\Tenant\Admin\RewardPointAdminController;
use App\Modules\RewardPoints\Http\Controllers\Tenant\RewardPointController;
use Illuminate\Support\Facades\Route;

/*
| Reward points (spec §54.3, cart route §38.7). Feature `reward_points`.
*/

Route::middleware(['tenant.storefront', 'feature:reward_points'])->name('tenant.storefront.')->group(function (): void {
    Route::post('cart/apply-reward-points', [RewardPointController::class, 'applyToCart'])->name('cart.reward-points.store');
});

Route::middleware(['tenant.customer', 'feature:reward_points'])->prefix('account')->name('tenant.customer.account.')->group(function (): void {
    Route::get('reward-points/balance', [RewardPointController::class, 'balance'])->name('reward-points.balance');
    Route::get('reward-points/history', [RewardPointController::class, 'history'])->name('reward-points.history');
});

Route::middleware(['tenant.admin', 'feature:reward_points', 'module.notice:reward_points'])->prefix('admin')->name('tenant.reward-points.')->group(function (): void {
    Route::get('reward-points/settings', [RewardPointAdminController::class, 'settings'])->name('settings.show');
    Route::put('reward-points/settings', [RewardPointAdminController::class, 'updateSettings'])->name('settings.update');
    Route::get('customers/{customer}/reward-points', [RewardPointAdminController::class, 'customer'])->whereNumber('customer')->name('customers.show');
    Route::post('customers/{customer}/reward-points/adjust', [RewardPointAdminController::class, 'adjust'])->whereNumber('customer')->name('customers.adjust');
});
