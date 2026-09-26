<?php

declare(strict_types=1);

use App\Modules\Plans\Http\Controllers\Landlord\Admin\PlanController as AdminPlanController;
use App\Modules\Plans\Http\Controllers\Landlord\Admin\PlanFeatureController;
use App\Modules\Plans\Http\Controllers\Landlord\Admin\PlanLimitController;
use App\Modules\Plans\Http\Controllers\Landlord\Admin\PlanPriceController;
use App\Modules\Plans\Http\Controllers\Landlord\Admin\TenantFeatureController;
use App\Modules\Plans\Http\Controllers\Landlord\Admin\TenantLimitOverrideController;
use App\Modules\Plans\Http\Controllers\Landlord\Admin\TenantModuleController;
use App\Modules\Plans\Http\Controllers\Landlord\PlanController;
use Illuminate\Support\Facades\Route;

/*
| Plans, features, limits and per-tenant overrides (spec §11.13).
*/

Route::middleware('landlord.public')->name('landlord.plans.public.')->group(function (): void {
    Route::get('plans', [PlanController::class, 'index'])->name('index');
    Route::get('plans/{slug}', [PlanController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('show');
});

Route::middleware('landlord.admin')->prefix('admin')->name('landlord.plans.')->group(function (): void {
    Route::get('plans', [AdminPlanController::class, 'index'])->name('index');
    Route::post('plans', [AdminPlanController::class, 'store'])->name('store');
    Route::get('plans/{plan}', [AdminPlanController::class, 'show'])->name('show');
    Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->name('update');
    Route::post('plans/{plan}/deactivate', [AdminPlanController::class, 'deactivate'])->name('deactivate');

    Route::get('plans/{plan}/prices', [PlanPriceController::class, 'index'])->name('prices.index');
    Route::post('plans/{plan}/prices', [PlanPriceController::class, 'store'])->name('prices.store');
    Route::patch('plans/{plan}/prices/{price}', [PlanPriceController::class, 'update'])->scopeBindings()->name('prices.update');

    Route::get('plans/{plan}/features', [PlanFeatureController::class, 'index'])->name('features.index');
    Route::post('plans/{plan}/features', [PlanFeatureController::class, 'store'])->name('features.store');
    Route::delete('plans/{plan}/features/{featureKey}', [PlanFeatureController::class, 'destroy'])->name('features.destroy');

    Route::get('plans/{plan}/limits', [PlanLimitController::class, 'index'])->name('limits.index');
    Route::post('plans/{plan}/limits', [PlanLimitController::class, 'store'])->name('limits.store');

    Route::get('tenants/{tenant}/modules', [TenantModuleController::class, 'index'])->name('tenant-modules.index');
    Route::get('tenants/{tenant}/features', [TenantFeatureController::class, 'index'])->name('tenant-features.index');
    Route::post('tenants/{tenant}/features', [TenantFeatureController::class, 'store'])->name('tenant-features.store');
    Route::delete('tenants/{tenant}/features/{featureKey}', [TenantFeatureController::class, 'destroy'])->name('tenant-features.destroy');
    Route::get('tenants/{tenant}/limit-overrides', [TenantLimitOverrideController::class, 'index'])->name('tenant-limit-overrides.index');
    Route::patch('tenants/{tenant}/limit-overrides', [TenantLimitOverrideController::class, 'update'])->name('tenant-limit-overrides.update');
    Route::delete('tenants/{tenant}/limit-overrides/{limitKey}', [TenantLimitOverrideController::class, 'destroy'])->name('tenant-limit-overrides.destroy');
});
