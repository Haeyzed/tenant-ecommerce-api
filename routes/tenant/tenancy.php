<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\Tenant\Admin\DomainController;
use Illuminate\Support\Facades\Route;

/*
| The tenant's domains (spec §7.5). Always available.
*/

Route::middleware('tenant.admin')->prefix('admin/domains')->name('tenant.domains.')->group(function (): void {
    Route::get('/', [DomainController::class, 'index'])->name('index');
    Route::post('/', [DomainController::class, 'store'])->middleware('usage.limit:max_custom_domains')->name('store');
    Route::get('{domain}', [DomainController::class, 'show'])->whereNumber('domain')->name('show');
    Route::post('{domain}/verify', [DomainController::class, 'verify'])->whereNumber('domain')->name('verify');
    Route::post('{domain}/make-primary', [DomainController::class, 'makePrimary'])->whereNumber('domain')->name('make-primary');
    Route::delete('{domain}', [DomainController::class, 'destroy'])->whereNumber('domain')->name('destroy');
});
