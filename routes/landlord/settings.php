<?php

declare(strict_types=1);

use App\Modules\Settings\Http\Controllers\Landlord\Admin\PlatformSettingsController;
use App\Modules\Settings\Http\Controllers\Landlord\Admin\PlatformSettingsMediaController;
use App\Modules\Settings\Http\Controllers\Landlord\Admin\TenantPlatformSettingsController;
use App\Modules\Settings\Http\Controllers\Landlord\PlatformConfigController;
use Illuminate\Support\Facades\Route;

/*
| Platform settings (spec §13.8).
*/

Route::middleware('landlord.public')->get('platform/config', [PlatformConfigController::class, 'show'])->name('landlord.platform.config');

Route::middleware('landlord.admin')->prefix('admin')->name('landlord.settings.')->group(function (): void {
    Route::get('platform-settings', [PlatformSettingsController::class, 'index'])->name('index');
    Route::get('platform-settings/{group}', [PlatformSettingsController::class, 'show'])->where('group', '[a-z_]+')->name('show');
    Route::patch('platform-settings/{group}', [PlatformSettingsController::class, 'update'])->where('group', '[a-z_]+')->name('update');

    // Image settings (BG-12): platform_logo, platform_favicon, seo_share_image.
    Route::post('platform-settings/media', [PlatformSettingsMediaController::class, 'store'])->name('media.store');
    Route::delete('platform-settings/media/{setting}', [PlatformSettingsMediaController::class, 'destroy'])->where('setting', '[a-z_]+')->name('media.destroy');

    Route::get('tenants/{tenant}/settings', [TenantPlatformSettingsController::class, 'show'])->name('tenant.show');
    Route::patch('tenants/{tenant}/settings', [TenantPlatformSettingsController::class, 'update'])->name('tenant.update');
});
