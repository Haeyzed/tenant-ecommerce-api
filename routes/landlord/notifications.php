<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Landlord\Admin\NotificationMatrixController;
use App\Modules\Notifications\Http\Controllers\Landlord\Admin\NotificationTemplateController;
use Illuminate\Support\Facades\Route;

/*
| Platform-owned notification templates (spec §17.7). Tenants cannot edit them.
*/

Route::middleware('landlord.admin')->prefix('admin')->name('landlord.notifications.')->group(function (): void {
    Route::get('notification-templates', [NotificationTemplateController::class, 'index'])->name('templates.index');
    Route::patch('notification-templates/{key}', [NotificationTemplateController::class, 'update'])->where('key', '[a-z0-9_.]+')->name('templates.update');
    Route::post('notification-templates/{key}/reset', [NotificationTemplateController::class, 'reset'])->where('key', '[a-z0-9_.]+')->name('templates.reset');

    Route::get('notifications/matrix', [NotificationMatrixController::class, 'index'])->name('matrix.index');
    Route::patch('notifications/matrix/{templateKey}', [NotificationMatrixController::class, 'update'])->where('templateKey', '[a-z0-9_.]+')->name('matrix.update');
});
