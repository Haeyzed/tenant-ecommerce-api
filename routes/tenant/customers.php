<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Tenant\AccountController;
use App\Modules\Customers\Http\Controllers\Tenant\AddressController;
use App\Modules\Customers\Http\Controllers\Tenant\Admin\CustomerController;
use App\Modules\Customers\Http\Controllers\Tenant\Admin\CustomerGroupController;
use Illuminate\Support\Facades\Route;

/*
| Customers, addresses and customer groups (spec §26.5). Always available.
*/

Route::middleware('tenant.customer')->prefix('account')->name('tenant.customer.account.')->group(function (): void {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::patch('/', [AccountController::class, 'update'])->name('update');
    Route::delete('/', [AccountController::class, 'destroy'])->middleware('throttle:auth-sensitive')->name('destroy');
    Route::post('export', [AccountController::class, 'export'])->name('export');

    Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::patch('addresses/{address}', [AddressController::class, 'update'])->whereNumber('address')->name('addresses.update');
    Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address')->name('addresses.destroy');
    Route::post('addresses/{address}/default', [AddressController::class, 'setDefault'])->whereNumber('address')->name('addresses.default');
});

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.customers.')->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])->name('index');
    Route::post('customers', [CustomerController::class, 'store'])->name('store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('show');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('update');
    Route::post('customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->whereNumber('customer')->name('deactivate');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->whereNumber('customer')->name('destroy');
    Route::post('customers/{customer}/export', [CustomerController::class, 'export'])->whereNumber('customer')->name('export');
    Route::post('customers/{customer}/assign-group', [CustomerController::class, 'assignGroup'])->whereNumber('customer')->name('assign-group');

    Route::get('customer-groups', [CustomerGroupController::class, 'index'])->name('groups.index');
    Route::post('customer-groups', [CustomerGroupController::class, 'store'])->name('groups.store');
    Route::patch('customer-groups/{group}', [CustomerGroupController::class, 'update'])->whereNumber('group')->name('groups.update');
    Route::delete('customer-groups/{group}', [CustomerGroupController::class, 'destroy'])->whereNumber('group')->name('groups.destroy');
});
