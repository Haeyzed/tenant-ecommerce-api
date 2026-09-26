<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\Landlord\Admin\PaymentGatewayController;
use App\Modules\Billing\Http\Controllers\Landlord\Admin\PaymentTransactionController;
use App\Modules\Billing\Http\Controllers\Landlord\Admin\PlatformCouponController as AdminPlatformCouponController;
use App\Modules\Billing\Http\Controllers\Landlord\Admin\PlatformCouponRedemptionController;
use App\Modules\Billing\Http\Controllers\Landlord\Admin\SubscriptionController;
use App\Modules\Billing\Http\Controllers\Landlord\BillingWebhookController;
use App\Modules\Billing\Http\Controllers\Landlord\PlatformCouponController;
use Illuminate\Support\Facades\Route;

/*
| Subscription billing, platform coupons and platform gateways (spec §14,
| §15.6, §15.10).
*/

Route::middleware(['landlord.public', 'throttle:auth-sensitive'])
    ->post('platform-coupons/validate', [PlatformCouponController::class, 'validate'])
    ->name('landlord.platform-coupons.validate');

// Signed by the provider; exempt from general throttling (§15.6 step 6).
Route::middleware(['landlord.webhooks', 'verify.webhook'])
    ->post('webhooks/{provider}/{mode}', [BillingWebhookController::class, 'handle'])
    ->where(['provider' => 'flutterwave|paystack|stripe', 'mode' => 'test|live'])
    ->name('landlord.billing.webhook');

Route::middleware('landlord.admin')->prefix('admin')->name('landlord.billing.')->group(function (): void {
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('subscriptions/metrics', [SubscriptionController::class, 'metrics'])->name('subscriptions.metrics');
    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])->name('subscriptions.show');
    Route::post('subscriptions/{subscription}/extend-trial', [SubscriptionController::class, 'extendTrial'])->name('subscriptions.extend-trial');

    Route::get('payment-transactions', [PaymentTransactionController::class, 'index'])->name('payment-transactions.index');
    Route::get('payment-transactions/metrics', [PaymentTransactionController::class, 'metrics'])->name('payment-transactions.metrics');
    Route::get('payment-transactions/{transaction}', [PaymentTransactionController::class, 'show'])->name('payment-transactions.show');
    Route::post('payment-transactions/{transaction}/refund', [PaymentTransactionController::class, 'refund'])
        ->middleware('idempotency')
        ->name('payment-transactions.refund');

    Route::get('platform-coupons', [AdminPlatformCouponController::class, 'index'])->name('platform-coupons.index');
    Route::get('platform-coupons/metrics', [AdminPlatformCouponController::class, 'metrics'])->name('platform-coupons.metrics');
    Route::post('platform-coupons', [AdminPlatformCouponController::class, 'store'])->name('platform-coupons.store');
    Route::get('platform-coupons/{coupon}', [AdminPlatformCouponController::class, 'show'])->name('platform-coupons.show');
    Route::patch('platform-coupons/{coupon}', [AdminPlatformCouponController::class, 'update'])->name('platform-coupons.update');
    Route::post('platform-coupons/{coupon}/deactivate', [AdminPlatformCouponController::class, 'deactivate'])->name('platform-coupons.deactivate');
    Route::get('platform-coupons/{coupon}/redemptions', [PlatformCouponRedemptionController::class, 'index'])->name('platform-coupons.redemptions.index');

    Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])->name('payment-gateways.index');
    Route::post('payment-gateways/mode', [PaymentGatewayController::class, 'mode'])->name('payment-gateways.mode');

    Route::prefix('payment-gateways/{provider}/{mode}')
        ->where(['provider' => 'flutterwave|paystack|stripe', 'mode' => 'test|live'])
        ->name('payment-gateways.')
        ->group(function (): void {
            Route::put('/', [PaymentGatewayController::class, 'update'])->name('update');
            Route::post('test', [PaymentGatewayController::class, 'test'])->name('test');
            Route::post('enable', [PaymentGatewayController::class, 'enable'])->name('enable');
            Route::post('disable', [PaymentGatewayController::class, 'disable'])->name('disable');
            Route::post('set-default', [PaymentGatewayController::class, 'setDefault'])->name('set-default');
        });
});
