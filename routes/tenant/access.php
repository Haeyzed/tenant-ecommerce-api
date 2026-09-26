<?php

declare(strict_types=1);

use App\Modules\Access\Http\Controllers\Tenant\Admin\PermissionController;
use App\Modules\Access\Http\Controllers\Tenant\Admin\RoleController;
use Illuminate\Support\Facades\Route;

/*
| Tenant roles and permissions (spec §12.3). Always available.
*/

Route::middleware('tenant.admin')->prefix('admin')->name('tenant.access.')->group(function (): void {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->name('roles.permissions');
    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
});
