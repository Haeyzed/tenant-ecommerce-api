<?php

declare(strict_types=1);

use App\Modules\Currency\Http\Controllers\Tenant\Admin\CurrencyController;
use App\Modules\Currency\Http\Controllers\Tenant\Admin\CurrencyExposureController;
use App\Modules\Currency\Http\Controllers\Tenant\Admin\ProductPriceController;
use Illuminate\Support\Facades\Route;

/*
| Multi-currency (spec §48.4). Feature `multi_currency`. The rate routes
| (manual rates and an on-demand refresh) add to §48.4 so a store can sell
| in a currency before an FX provider is chosen (UD-19). The storefront's
| cart currency route is in routes/tenant/cart.php.
*/

Route::middleware(['tenant.admin', 'feature:multi_currency', 'module.notice:multi_currency'])->prefix('admin')->group(function (): void {
    Route::name('tenant.currencies.')->group(function (): void {
        Route::get('currencies', [CurrencyController::class, 'index'])->name('index');
        Route::post('currencies', [CurrencyController::class, 'store'])->name('store');
        Route::post('currencies/refresh-rates', [CurrencyController::class, 'refreshRates'])->name('refresh-rates');
        Route::patch('currencies/{currency}/deactivate', [CurrencyController::class, 'deactivate'])->whereNumber('currency')->name('deactivate');
        Route::post('currencies/{currency}/set-base', [CurrencyController::class, 'setBase'])->whereNumber('currency')->name('set-base');
        Route::put('currencies/{currency}/rate', [CurrencyController::class, 'setRate'])->whereNumber('currency')->name('rate.update');
        Route::delete('currencies/{currency}/rate', [CurrencyController::class, 'clearRate'])->whereNumber('currency')->name('rate.destroy');
    });

    Route::name('tenant.products.prices.')->group(function (): void {
        Route::get('products/{product}/prices', [ProductPriceController::class, 'index'])->whereNumber('product')->name('index');
        Route::post('products/{product}/prices', [ProductPriceController::class, 'store'])->whereNumber('product')->name('store');
        Route::patch('products/{product}/prices/{price}', [ProductPriceController::class, 'update'])->whereNumber(['product', 'price'])->name('update');
        Route::delete('products/{product}/prices/{price}', [ProductPriceController::class, 'destroy'])->whereNumber(['product', 'price'])->name('destroy');
    });

    Route::get('accounting/reports/currency-exposure', [CurrencyExposureController::class, 'show'])
        ->middleware('feature:accounting')
        ->name('tenant.accounting.reports.currency-exposure');
});
