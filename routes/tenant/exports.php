<?php

declare(strict_types=1);

use App\Modules\Exports\Http\Controllers\Tenant\Admin\DataExportController;
use App\Modules\Exports\Http\Controllers\Tenant\SignedExportDownloadController;
use Illuminate\Support\Facades\Route;

/*
| Asynchronous exports (spec §19.4). Always available; each export type
| checks its own permission and feature.
*/

Route::middleware('tenant.admin')->prefix('admin/exports')->name('tenant.exports.')->group(function (): void {
    Route::get('/', [DataExportController::class, 'index'])->name('index');
    Route::post('/', [DataExportController::class, 'store'])->name('store');
    Route::get('{export}', [DataExportController::class, 'show'])->whereNumber('export')->name('show');
    Route::get('{export}/download', [DataExportController::class, 'download'])->whereNumber('export')->name('download');
});

Route::middleware(['tenant.public', 'signed'])
    ->get('exports/{export}/download', SignedExportDownloadController::class)
    ->whereNumber('export')
    ->name('tenant.exports.signed-download');
