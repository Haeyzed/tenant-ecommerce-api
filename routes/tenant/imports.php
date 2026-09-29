<?php

declare(strict_types=1);

use App\Modules\Imports\Http\Controllers\Tenant\Admin\DataImportController;
use Illuminate\Support\Facades\Route;

/*
| Spreadsheet imports (D-135). Core platform; each import type checks its
| own permission (for example products.create) on top of the route's.
*/

Route::middleware('tenant.admin')->prefix('admin/imports')->name('tenant.imports.')->group(function (): void {
    Route::get('types', [DataImportController::class, 'types'])->name('types');
    Route::get('types/{type}/template', [DataImportController::class, 'template'])->where('type', '[a-z_]+')->name('template');
    Route::get('/', [DataImportController::class, 'index'])->name('index');
    Route::post('/', [DataImportController::class, 'store'])->middleware('usage.limit:max_storage_mb')->name('store');
    Route::get('{import}', [DataImportController::class, 'show'])->whereNumber('import')->name('show');
    Route::get('{import}/errors', [DataImportController::class, 'errors'])->whereNumber('import')->name('errors');
});
