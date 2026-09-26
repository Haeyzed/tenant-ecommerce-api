<?php

declare(strict_types=1);

use App\Modules\Tax\Http\Controllers\Tenant\Admin\TaxRateController;
use Illuminate\Support\Facades\Route;

/*
| Tax rates (spec §35.4). Core commerce; no customer routes.
*/

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.tax.admin.')->group(function (): void {
    Route::get('tax-rates', [TaxRateController::class, 'index'])->name('rates.index');
    Route::post('tax-rates', [TaxRateController::class, 'store'])->name('rates.store');
    Route::patch('tax-rates/{rate}', [TaxRateController::class, 'update'])->whereNumber('rate')->name('rates.update');
    Route::delete('tax-rates/{rate}', [TaxRateController::class, 'destroy'])->whereNumber('rate')->name('rates.destroy');
});
