<?php

declare(strict_types=1);

use App\Modules\Plans\Http\Controllers\Tenant\Admin\ModuleController;
use Illuminate\Support\Facades\Route;

/*
| The tenant's modules (spec §11.13). Always available; listing needs no
| permission, enable and disable are derived (modules.enable|disable).
*/

Route::middleware('tenant.admin')->prefix('admin/modules')->name('tenant.modules.')->group(function (): void {
    Route::get('/', [ModuleController::class, 'index'])->withoutMiddleware('permission.derived')->name('index');
    Route::post('{moduleKey}/enable', [ModuleController::class, 'enable'])->where('moduleKey', '[a-z_]+')->name('enable');
    Route::post('{moduleKey}/disable', [ModuleController::class, 'disable'])->where('moduleKey', '[a-z_]+')->name('disable');
});
