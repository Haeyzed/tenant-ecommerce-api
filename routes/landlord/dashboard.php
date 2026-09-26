<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Landlord\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| The landlord dashboard (spec §22.3, §22.8). Every section is one request;
| each section's own data permission is checked by the dashboard service.
*/

Route::middleware('landlord.admin')->prefix('admin')->name('landlord.dashboard.')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('index');
    Route::get('dashboard/{section}', [DashboardController::class, 'show'])->where('section', '[a-z_]+')->name('show');
});
