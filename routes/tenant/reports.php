<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\Tenant\Admin\ReportController;
use App\Modules\Reporting\Services\ReportService;
use Illuminate\Support\Facades\Route;

/*
| Advanced reporting (spec §61.3). Feature `advanced_reporting`, plus the
| optional module a report reads (§61.2). Every report is
| ReportController@show with its key as a route default, so the permission
| derives as reports.{key}.view (e.g. reports.profit-by-product.view).
| Exports: POST /api/admin/reports/{reportKey}/export (reports.export, and
| the report's own view permission, checked by the export service).
*/

Route::middleware(['tenant.admin', 'feature:advanced_reporting', 'module.notice:advanced_reporting'])->prefix('admin/reports')->name('tenant.admin.reports.')->group(function (): void {
    foreach (ReportService::catalogue() as $key => [, $feature]) {
        Route::get($key, [ReportController::class, 'show'])
            ->defaults('report', $key)
            ->middleware($feature === null ? [] : ["feature:{$feature}"])
            ->name(str_replace('/', '.', $key));
    }

    Route::post('{reportKey}/export', [ReportController::class, 'export'])
        ->where('reportKey', implode('|', array_map(static fn (string $key): string => preg_quote($key, '#'), array_keys(ReportService::catalogue()))))
        ->name('export');
});
