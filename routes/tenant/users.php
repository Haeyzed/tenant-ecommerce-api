<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\Tenant\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
| Staff users (spec §25.4). Always available. PUT users/{user}/warehouses
| is added with warehouses (§32.3).
*/

Route::middleware('tenant.admin')->prefix('admin')->name('tenant.users.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('index');
    Route::post('users', [UserController::class, 'store'])->middleware('usage.limit:max_users')->name('store');
    Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('show');
    Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('destroy');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->whereNumber('user')->name('deactivate');
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->whereNumber('user')->middleware('usage.limit:max_users')->name('reactivate');
    Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->whereNumber('user')->name('roles');
    Route::put('users/{user}/permissions', [UserController::class, 'syncPermissions'])->whereNumber('user')->name('permissions');
    Route::post('users/{user}/transfer-ownership', [UserController::class, 'transferOwnership'])->whereNumber('user')->name('transfer-ownership');
});
