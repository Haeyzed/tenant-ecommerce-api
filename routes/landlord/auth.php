<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Landlord\AuthController;
use Illuminate\Support\Facades\Route;

/*
| Platform-user authentication (spec §10.5). Credential-bearing requests
| use the auth-sensitive limiter; authenticated self-service routes use the
| per-actor api limiter.
*/

Route::middleware('landlord.public')->prefix('admin/auth')->name('landlord.auth.')->group(function (): void {
    Route::middleware('throttle:auth-sensitive')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('email/verify', [AuthController::class, 'verifyEmail'])->name('email.verify');
        Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->name('password.forgot');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->name('password.reset');
        Route::post('email/resend', [AuthController::class, 'resendVerificationEmail'])
            ->middleware('auth.as:platform')
            ->name('email.resend');
    });

    Route::middleware(['auth.as:platform', 'throttle:api'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::patch('preferences', [AuthController::class, 'updatePreferences'])->name('preferences');
    });
});
