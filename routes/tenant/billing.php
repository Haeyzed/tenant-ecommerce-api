<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\Tenant\Admin\BillingController;
use App\Modules\Billing\Http\Controllers\Tenant\UsageController;
use Illuminate\Support\Facades\Route;

/*
| The tenant owner's billing (spec §14.6, §11.13). Always available; the
| billing routes stay reachable in every restricted subscription state
| (tenant.active exempts /api/admin/billing).
*/

Route::middleware('tenant.admin')->prefix('admin/billing')->name('tenant.billing.')->group(function (): void {
    Route::get('plans', [BillingController::class, 'plans'])->name('plans');
    Route::get('subscription', [BillingController::class, 'current'])->name('subscription.current');
    Route::post('subscription', [BillingController::class, 'subscribe'])->middleware('idempotency')->name('subscription.subscribe');
    Route::post('coupon-preview', [BillingController::class, 'couponPreview'])->name('coupon-preview');
    Route::get('subscription/plan-change-preview', [BillingController::class, 'preview'])->name('subscription.plan-change-preview');
    Route::post('subscription/swap-plan', [BillingController::class, 'swapPlan'])->middleware('idempotency')->name('subscription.swap-plan');
    Route::post('subscription/cancel', [BillingController::class, 'cancel'])->name('subscription.cancel');
    Route::get('transactions', [BillingController::class, 'transactions'])->name('transactions');
    Route::get('usage', [UsageController::class, 'index'])->name('usage');
});
