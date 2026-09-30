<?php

declare(strict_types=1);

use App\Modules\Settings\Http\Controllers\Tenant\Admin\SettingsMediaController;
use App\Modules\Settings\Http\Controllers\Tenant\Admin\StorefrontSettingsController;
use App\Modules\Settings\Http\Controllers\Tenant\Admin\TenantSettingsController;
use App\Modules\Settings\Http\Controllers\Tenant\StorefrontConfigController;
use Illuminate\Support\Facades\Route;

/*
| Tenant settings (spec §13.8). Always available.
*/

Route::middleware('tenant.public')->get('storefront/config', [StorefrontConfigController::class, 'show'])->name('tenant.storefront.config');

Route::middleware('tenant.admin')->prefix('admin')->name('tenant.settings.')->group(function (): void {
    Route::get('settings', [TenantSettingsController::class, 'show'])->name('show');
    Route::patch('settings', [TenantSettingsController::class, 'update'])->name('update');
    Route::get('storefront-settings', [StorefrontSettingsController::class, 'show'])->name('storefront.show');
    Route::patch('storefront-settings', [StorefrontSettingsController::class, 'update'])->name('storefront.update');

    // Image settings (BG-12): store_logo, favicon, seo_share_image.
    Route::post('settings/media', [SettingsMediaController::class, 'store'])->middleware('usage.limit:max_storage_mb')->name('media.store');
    Route::delete('settings/media/{setting}', [SettingsMediaController::class, 'destroy'])->where('setting', '[a-z_]+')->name('media.destroy');
});
