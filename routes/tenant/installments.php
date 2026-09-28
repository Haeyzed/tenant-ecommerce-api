<?php

declare(strict_types=1);

use App\Modules\Installments\Http\Controllers\Tenant\Admin\InstallmentPlanController;
use App\Modules\Installments\Http\Controllers\Tenant\InstallmentController;
use Illuminate\Support\Facades\Route;

/*
| Installment payments (spec §47.5). Feature `installments`; storefront
| routes are for the order's owner. Paying an installment stays available
| while the module winds down, so open plans can be settled.
*/

Route::middleware(['tenant.storefront', 'feature:installments'])->name('tenant.storefront.')->group(function (): void {
    Route::get('orders/{order}/installment-eligibility', [InstallmentController::class, 'eligibility'])->whereNumber('order')->name('orders.installment-eligibility');
    Route::post('orders/{order}/installment-plan', [InstallmentController::class, 'store'])->whereNumber('order')->name('orders.installment-plan.store');
    Route::get('orders/{order}/installment-plan', [InstallmentController::class, 'show'])->whereNumber('order')->name('orders.installment-plan.show');
    Route::post('installment-payments/{payment}/pay', [InstallmentController::class, 'pay'])->whereNumber('payment')->middleware('idempotency')->name('installment-payments.pay');
});

Route::middleware(['tenant.admin', 'feature:installments', 'module.notice:installments'])->prefix('admin')->name('tenant.installment-plans.')->group(function (): void {
    Route::get('installment-plans', [InstallmentPlanController::class, 'index'])->name('index');
    Route::get('installment-plans/{plan}', [InstallmentPlanController::class, 'show'])->whereNumber('plan')->name('show');
    Route::patch('installment-plans/{plan}/cancel', [InstallmentPlanController::class, 'cancel'])->whereNumber('plan')->name('cancel');
    Route::post('installment-plans/{plan}/payments/{payment}/charge', [InstallmentPlanController::class, 'charge'])->whereNumber(['plan', 'payment'])->middleware('idempotency')->name('payments.charge');
});
