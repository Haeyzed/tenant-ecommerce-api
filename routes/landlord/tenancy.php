<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\Landlord\Admin\DatabaseServerController;
use App\Modules\Tenancy\Http\Controllers\Landlord\Admin\TenantManagementController;
use App\Modules\Tenancy\Http\Controllers\Landlord\Admin\TenantRegistrationController;
use App\Modules\Tenancy\Http\Controllers\Landlord\EdgeDomainController;
use App\Modules\Tenancy\Http\Controllers\Landlord\RegistrationController;
use App\Modules\Tenancy\Http\Controllers\Landlord\TenantExportDownloadController;
use Illuminate\Support\Facades\Route;

/*
| Self-service registration (spec §9.8). Every route uses auth-sensitive.
*/

Route::middleware(['landlord.public', 'throttle:auth-sensitive'])->prefix('register')->name('landlord.register.')->group(function (): void {
    Route::post('/', [RegistrationController::class, 'store'])->name('store');
    Route::post('verify', [RegistrationController::class, 'verify'])->name('verify');
    Route::post('resend', [RegistrationController::class, 'resend'])->name('resend');
    Route::get('{registration}/status', [RegistrationController::class, 'status'])->whereUuid('registration')->name('status');
    Route::post('{registration}/checkout', [RegistrationController::class, 'checkout'])->whereUuid('registration')->name('checkout');
});

Route::middleware('landlord.admin')->prefix('admin')->name('landlord.tenancy.')->group(function (): void {
    Route::get('tenant-registrations', [TenantRegistrationController::class, 'index'])->name('registrations.index');

    // Tenant management (§7.4).
    Route::get('tenants', [TenantManagementController::class, 'index'])->name('tenants.index');
    Route::get('tenants/metrics', [TenantManagementController::class, 'metrics'])->name('tenants.metrics');
    Route::get('tenants/{tenant}', [TenantManagementController::class, 'show'])->name('tenants.show');
    Route::post('tenants/{tenant}/suspend', [TenantManagementController::class, 'suspend'])->name('tenants.suspend');
    Route::post('tenants/{tenant}/reactivate', [TenantManagementController::class, 'reactivate'])->name('tenants.reactivate');
    Route::post('tenants/{tenant}/close', [TenantManagementController::class, 'close'])->name('tenants.close');
    Route::post('tenants/{tenant}/restore', [TenantManagementController::class, 'restore'])->name('tenants.restore');
    Route::post('tenants/{tenant}/export', [TenantManagementController::class, 'export'])->name('tenants.export');

    Route::get('database-servers', [DatabaseServerController::class, 'index'])->name('database-servers.index');
    Route::post('database-servers', [DatabaseServerController::class, 'store'])->name('database-servers.store');
    Route::patch('database-servers/{server}', [DatabaseServerController::class, 'update'])->name('database-servers.update');
});

// Emailed, temporary signed link to a tenant export (§6.7).
Route::middleware(['landlord.public', 'signed'])
    ->get('tenant-exports/{file}', TenantExportDownloadController::class)
    ->where('file', '[A-Za-z0-9._-]+\.zip')
    ->name('landlord.tenant-exports.download');

// The edge proxy's TLS issuance check (§7.5 rule 2).
Route::middleware('landlord.internal')
    ->get('internal/domains/allowed', [EdgeDomainController::class, 'allowed'])
    ->name('landlord.internal.domains.allowed');
