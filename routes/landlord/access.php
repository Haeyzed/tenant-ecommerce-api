<?php

declare(strict_types=1);

use App\Modules\Access\Http\Controllers\Landlord\Admin\PlatformUserController;
use Illuminate\Support\Facades\Route;

/*
| Platform users and roles (spec §12.2).
*/

Route::middleware('landlord.admin')->prefix('admin/platform-users')->name('landlord.platform-users.')->group(function (): void {
    Route::get('/', [PlatformUserController::class, 'index'])->name('index');
    Route::post('/', [PlatformUserController::class, 'store'])->name('store');
    Route::patch('{user}', [PlatformUserController::class, 'update'])->name('update');
    Route::post('{user}/deactivate', [PlatformUserController::class, 'deactivate'])->name('deactivate');
    Route::post('{user}/roles', [PlatformUserController::class, 'assignRole'])->name('roles.assign');
    Route::delete('{user}/roles/{role}', [PlatformUserController::class, 'revokeRole'])->where('role', '[a-z-]+')->name('roles.revoke');
});
