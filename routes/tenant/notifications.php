<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Tenant\Admin\InboxController;
use App\Modules\Notifications\Http\Controllers\Tenant\Admin\NotificationMatrixController;
use App\Modules\Notifications\Http\Controllers\Tenant\Admin\NotificationPreferenceController;
use App\Modules\Notifications\Http\Controllers\Tenant\Admin\NotificationTemplateController;
use Illuminate\Support\Facades\Route;

/*
| Tenant notifications (spec §17.7). Inbox and preferences are self-service
| (no permission). Customer routes are added with the Customers module.
*/

Route::middleware('tenant.admin')->prefix('admin')->name('tenant.notifications.')->group(function (): void {
    Route::get('notification-templates', [NotificationTemplateController::class, 'index'])->name('templates.index');
    Route::patch('notification-templates/{key}', [NotificationTemplateController::class, 'update'])->where('key', '[a-z0-9_.]+')->name('templates.update');
    Route::post('notification-templates/{key}/reset', [NotificationTemplateController::class, 'reset'])->where('key', '[a-z0-9_.]+')->name('templates.reset');

    Route::get('notifications/matrix', [NotificationMatrixController::class, 'index'])->name('matrix.index');
    Route::patch('notifications/matrix/{templateKey}', [NotificationMatrixController::class, 'update'])->where('templateKey', '[a-z0-9_.]+')->name('matrix.update');

    Route::withoutMiddleware('permission.derived')->group(function (): void {
        Route::get('notifications', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('notifications/{id}/read', [InboxController::class, 'markRead'])->whereUuid('id')->name('inbox.read');

        Route::get('notification-preferences', [NotificationPreferenceController::class, 'index'])->name('preferences.index');
        Route::patch('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('preferences.update');
        Route::post('notification-preferences/reset', [NotificationPreferenceController::class, 'reset'])->name('preferences.reset');
    });
});
