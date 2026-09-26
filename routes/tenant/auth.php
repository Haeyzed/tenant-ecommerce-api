<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Tenant\CustomerAuthController;
use App\Modules\Auth\Http\Controllers\Tenant\DriverAuthController;
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

/*
| Customer authentication (spec §10.5). register and login read
| X-Guest-Token (guest.token) to merge the guest cart.
*/

Route::middleware('tenant.public')->prefix('auth')->name('tenant.auth.customer.')->group(function (): void {
    Route::middleware('throttle:auth-sensitive')->group(function (): void {
        Route::post('register', [CustomerAuthController::class, 'register'])->middleware('guest.token')->name('register');
        Route::post('login', [CustomerAuthController::class, 'login'])->middleware('guest.token')->name('login');
        Route::post('email/verify', [CustomerAuthController::class, 'verifyEmail'])->name('email.verify');
        Route::post('password/forgot', [CustomerAuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('password/reset', [CustomerAuthController::class, 'resetPassword'])->name('password.reset');
        Route::post('email/resend', [CustomerAuthController::class, 'resendVerificationEmail'])->middleware('auth.as:customer')->name('email.resend');
        Route::patch('password', [CustomerAuthController::class, 'changePassword'])->middleware('auth.as:customer')->name('password.change');
    });

    Route::post('logout', [CustomerAuthController::class, 'logout'])->middleware(['auth.as:customer', 'throttle:api'])->name('logout');
});

/*
| Driver authentication (spec §10.5): phone and PIN; an SMS code sets the
| PIN on first login and resets it. Core commerce.
*/

Route::middleware('tenant.public')->prefix('driver/auth')->name('tenant.auth.driver.')->group(function (): void {
    Route::middleware('throttle:auth-sensitive')->group(function (): void {
        Route::post('request-otp', [DriverAuthController::class, 'requestOtp'])->name('request-otp');
        Route::post('verify-otp', [DriverAuthController::class, 'verifyOtp'])->name('verify-otp');
        Route::post('login', [DriverAuthController::class, 'login'])->name('login');
    });

    Route::post('logout', [DriverAuthController::class, 'logout'])->middleware(['auth.as:driver', 'throttle:api'])->name('logout');
});
