<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\Tenant\Admin\DomainController;
use App\Modules\Tenancy\Http\Controllers\Tenant\Admin\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
| The onboarding checklist (spec §9.6): any staff user, no permission.
*/

Route::middleware('tenant.admin')->withoutMiddleware('permission.derived')->prefix('admin')->name('tenant.onboarding.')->group(function (): void {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('show');
});

/*
| The tenant's domains (spec §7.5). Always available.
*/

Route::middleware('tenant.admin')->prefix('admin/domains')->name('tenant.domains.')->group(function (): void {
    Route::get('/', [DomainController::class, 'index'])->name('index');
    Route::post('/', [DomainController::class, 'store'])->middleware('usage.limit:max_custom_domains')->name('store');
    Route::get('{domain}', [DomainController::class, 'show'])->whereNumber('domain')->name('show');
    Route::post('{domain}/verify', [DomainController::class, 'verify'])->whereNumber('domain')->name('verify');
    Route::post('{domain}/make-primary', [DomainController::class, 'makePrimary'])->whereNumber('domain')->name('make-primary');
    Route::delete('{domain}', [DomainController::class, 'destroy'])->whereNumber('domain')->name('destroy');
});
