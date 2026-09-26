<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\Tenant\Admin\MailSettingsController;
use App\Modules\Messaging\Http\Controllers\Tenant\Admin\SmsGatewaySettingsController;
use App\Modules\Messaging\Http\Controllers\Tenant\Admin\WhatsAppSettingsController;
use App\Modules\Messaging\Http\Controllers\Tenant\PushDeviceTokenController;
use Illuminate\Support\Facades\Route;

/*
| Message delivery settings (spec §16). SMS and mail are always available;
| WhatsApp requires the whatsapp module; push tokens are self-service.
| Customer, seller and driver token routes are added with those actors.
*/

Route::middleware('tenant.admin')->prefix('admin')->name('tenant.messaging.')->group(function (): void {
    Route::post('settings/mail/test', [MailSettingsController::class, 'test'])->name('mail.test');

    Route::get('sms-gateway-settings', [SmsGatewaySettingsController::class, 'index'])->name('sms.index');
    Route::post('sms-gateway-settings', [SmsGatewaySettingsController::class, 'store'])->name('sms.store');

    Route::prefix('sms-gateway-settings/{gateway}')->where(['gateway' => 'termii|africas_talking'])->name('sms.')->group(function (): void {
        Route::patch('set-default', [SmsGatewaySettingsController::class, 'setDefault'])->name('set-default');
        Route::patch('activate', [SmsGatewaySettingsController::class, 'activate'])->name('activate');
        Route::patch('deactivate', [SmsGatewaySettingsController::class, 'deactivate'])->name('deactivate');
    });

    Route::middleware(['feature:whatsapp', 'module.notice:whatsapp'])->prefix('whatsapp-settings')->name('whatsapp.')->group(function (): void {
        Route::get('/', [WhatsAppSettingsController::class, 'show'])->name('show');
        Route::put('/', [WhatsAppSettingsController::class, 'update'])->name('update');
        Route::post('test', [WhatsAppSettingsController::class, 'test'])->name('test');
        Route::post('activate', [WhatsAppSettingsController::class, 'activate'])->name('activate');
        Route::post('deactivate', [WhatsAppSettingsController::class, 'deactivate'])->name('deactivate');
    });

    Route::withoutMiddleware('permission.derived')->group(function (): void {
        Route::post('push-tokens', [PushDeviceTokenController::class, 'store'])->name('push-tokens.store');
        Route::delete('push-tokens', [PushDeviceTokenController::class, 'destroy'])->name('push-tokens.destroy');
    });
});
