<?php

declare(strict_types=1);

use App\Modules\PlatformSupport\Http\Controllers\Tenant\Admin\PlatformSupportController;
use Illuminate\Support\Facades\Route;

/*
| Tenant staff asking the platform for help (spec §21.4). No feature check;
| permission "none": any authenticated staff user.
*/

Route::middleware('tenant.admin')
    ->withoutMiddleware('permission.derived')
    ->prefix('admin/platform-support/conversations')
    ->name('tenant.platform-support.')
    ->group(function (): void {
        Route::get('/', [PlatformSupportController::class, 'index'])->name('index');
        Route::post('/', [PlatformSupportController::class, 'store'])->name('store');
        Route::get('{conversation}', [PlatformSupportController::class, 'show'])->whereNumber('conversation')->name('show');
        Route::post('{conversation}/messages', [PlatformSupportController::class, 'sendMessage'])->whereNumber('conversation')->name('messages.store');
    });
