<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\DashboardController;
use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use Illuminate\Support\Facades\Route;

/*
| The tenant dashboard and the contextual KPI strips of the core list
| screens (spec §44.3 to §44.5). Core commerce. Every {resource}/metrics
| route derives {resource}.view; optional modules register their own.
*/

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.dashboard.')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('index');
    Route::get('dashboard/{section}', [DashboardController::class, 'show'])->where('section', '[a-z_]+')->name('show');

    foreach (ResourceMetricsController::resources() as $resource) {
        Route::get("{$resource}/metrics", [ResourceMetricsController::class, 'metrics'])
            ->defaults('metrics_resource', $resource)
            ->name("metrics.{$resource}");
    }
});
