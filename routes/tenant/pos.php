<?php

declare(strict_types=1);

use App\Modules\Pos\Http\Controllers\Tenant\Admin\ProductLookupController;
use App\Modules\Pos\Http\Controllers\Tenant\Admin\RegisterController;
use App\Modules\Pos\Http\Controllers\Tenant\Admin\SaleController;
use App\Modules\Pos\Http\Controllers\Tenant\Admin\SessionController;
use App\Modules\Pos\Http\Controllers\Tenant\Admin\SettingsController;
use App\Modules\Pos\Http\Controllers\Tenant\Admin\TerminalChargeController;
use Illuminate\Support\Facades\Route;

/*
| Point of sale (spec §51.8). Feature `pos`; cashiers are staff, governed by
| the derived pos.* permissions. Closing a session and syncing queued sales
| stay available while the module winds down (config/modules.php).
| Addition to §51.8: registers/{register}/activate (reactivation counts
| against max_pos_registers, §11.10).
*/

Route::middleware(['tenant.admin', 'feature:pos', 'module.notice:pos'])->prefix('admin/pos')->name('tenant.admin.pos.')->group(function (): void {
    Route::get('registers', [RegisterController::class, 'index'])->name('registers.index');
    Route::post('registers', [RegisterController::class, 'store'])->middleware('usage.limit:max_pos_registers')->name('registers.store');
    Route::patch('registers/{register}', [RegisterController::class, 'update'])->whereNumber('register')->name('registers.update');
    Route::delete('registers/{register}', [RegisterController::class, 'destroy'])->whereNumber('register')->name('registers.destroy');
    Route::patch('registers/{register}/activate', [RegisterController::class, 'activate'])->whereNumber('register')->middleware('usage.limit:max_pos_registers')->name('registers.activate');
    Route::patch('registers/{register}/terminal', [RegisterController::class, 'terminal'])->whereNumber('register')->name('registers.terminal');
    Route::get('registers/{register}/current-session', [SessionController::class, 'current'])->whereNumber('register')->name('registers.current-session');

    Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::post('sessions', [SessionController::class, 'store'])->name('sessions.store');
    Route::get('sessions/{session}', [SessionController::class, 'show'])->whereNumber('session')->name('sessions.show');
    Route::post('sessions/{session}/close', [SessionController::class, 'close'])->whereNumber('session')->name('sessions.close');

    Route::get('products/lookup', [ProductLookupController::class, 'show'])->name('products.lookup');

    Route::post('terminal-charges', [TerminalChargeController::class, 'store'])->name('terminal-charges.store');
    Route::get('terminal-charges/{reference}', [TerminalChargeController::class, 'show'])->where('reference', 'PTC-[A-Z0-9]{16}')->name('terminal-charges.show');

    Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
    Route::post('quote', [SaleController::class, 'quote'])->name('quote');
    Route::post('sales', [SaleController::class, 'store'])->middleware('usage.limit:max_orders_per_month')->name('sales.store');
    Route::post('sales/{order}/void', [SaleController::class, 'void'])->whereNumber('order')->name('sales.void');
    Route::get('sales/{order}/receipt', [SaleController::class, 'receipt'])->whereNumber('order')->name('sales.receipt');

    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
});
