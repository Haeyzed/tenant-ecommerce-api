<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\Tenant\Admin\PaymentSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Tenant gateway credentials and payment mode (spec §15.4). Always available.
*/

Route::middleware('tenant.admin')->prefix('admin/payment-settings')->name('tenant.payment-settings.')->group(function (): void {
    Route::get('/', [PaymentSettingsController::class, 'index'])->name('index');
    Route::patch('mode', [PaymentSettingsController::class, 'mode'])->name('mode');

    Route::prefix('{provider}/{mode}')
        ->where(['provider' => 'flutterwave|paystack|stripe', 'mode' => 'test|live'])
        ->group(function (): void {
            Route::put('/', [PaymentSettingsController::class, 'update'])->name('update');
            Route::post('test', [PaymentSettingsController::class, 'test'])->name('test');
            Route::patch('activate', [PaymentSettingsController::class, 'activate'])->name('activate');
            Route::patch('deactivate', [PaymentSettingsController::class, 'deactivate'])->name('deactivate');
            Route::patch('online-availability', [PaymentSettingsController::class, 'onlineAvailability'])->name('online-availability');
        });
});
