<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Tenant\StaffAuthController;
use Illuminate\Support\Facades\Route;

/*
| Staff authentication (spec §10.5). There is no staff self-registration.
*/

Route::middleware('tenant.public')->prefix('admin/auth')->name('tenant.auth.staff.')->group(function (): void {
    Route::middleware('throttle:auth-sensitive')->group(function (): void {
        Route::post('login', [StaffAuthController::class, 'login'])->name('login');
        Route::post('password/forgot', [StaffAuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('password/reset', [StaffAuthController::class, 'resetPassword'])->name('password.reset');
        Route::patch('password', [StaffAuthController::class, 'changePassword'])
            ->middleware('auth.as:staff')
            ->name('password.change');
    });

    Route::middleware(['auth.as:staff', 'throttle:api'])->group(function (): void {
        Route::post('logout', [StaffAuthController::class, 'logout'])->name('logout');
        Route::post('refresh', [StaffAuthController::class, 'refresh'])->name('refresh');
        Route::get('me', [StaffAuthController::class, 'me'])->name('me');
        Route::patch('preferences', [StaffAuthController::class, 'updatePreferences'])->name('preferences');
    });
});
