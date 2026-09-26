<?php

declare(strict_types=1);

use App\Modules\ModuleNotices\Http\Controllers\Landlord\Admin\ModuleNoticeController;
use Illuminate\Support\Facades\Route;

/*
| Module notices and maintenance (spec §18.5).
*/

Route::middleware('landlord.admin')->prefix('admin')->name('landlord.module-notices.')->group(function (): void {
    Route::get('module-notices', [ModuleNoticeController::class, 'index'])->name('index');
    Route::post('module-notices', [ModuleNoticeController::class, 'store'])->name('store');
    Route::patch('module-notices/{notice}', [ModuleNoticeController::class, 'update'])->name('update');
    Route::delete('module-notices/{notice}', [ModuleNoticeController::class, 'destroy'])->name('destroy');
});
