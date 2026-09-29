<?php

declare(strict_types=1);

use App\Modules\Exports\Http\Controllers\Landlord\Admin\PlatformExportController;
use Illuminate\Support\Facades\Route;

/*
| Platform exports (D-134): tenants, subscriptions, payments, affiliates and
| payouts. Each type also needs its list's view permission.
*/

Route::middleware('landlord.admin')->prefix('admin/exports')->name('landlord.exports.')->group(function (): void {
    Route::get('/', [PlatformExportController::class, 'index'])->name('index');
    Route::post('/', [PlatformExportController::class, 'store'])->name('store');
    Route::get('{export}', [PlatformExportController::class, 'show'])->whereNumber('export')->name('show');
    Route::get('{export}/download', [PlatformExportController::class, 'download'])->whereNumber('export')->name('download');
});
