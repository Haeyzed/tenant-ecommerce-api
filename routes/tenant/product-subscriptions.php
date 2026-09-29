<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\ProductSubscriptions\Http\Controllers\Tenant\Admin\SubscriptionController as AdminSubscriptionController;
use App\Modules\ProductSubscriptions\Http\Controllers\Tenant\Admin\SubscriptionPlanController as AdminSubscriptionPlanController;
use App\Modules\ProductSubscriptions\Http\Controllers\Tenant\SubscriptionController;
use App\Modules\ProductSubscriptions\Http\Controllers\Tenant\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

/*
| Product subscriptions (spec §55.3). Feature `product_subscriptions`. The
| customer's pause, resume and cancel stay available while the module
| winds down (§11.5), so existing subscriptions can still be managed.
*/

Route::middleware(['tenant.public', 'feature:product_subscriptions'])->name('tenant.public.')->group(function (): void {
    Route::get('products/{product}/subscription-plans', [SubscriptionPlanController::class, 'index'])->where('product', '[A-Za-z0-9-]+')->name('products.subscription-plans.index');
});

Route::middleware(['tenant.customer', 'feature:product_subscriptions'])->prefix('account')->name('tenant.customer.account.')->group(function (): void {
    Route::get('product-subscriptions', [SubscriptionController::class, 'index'])->name('product-subscriptions.index');
    Route::post('product-subscriptions', [SubscriptionController::class, 'store'])->middleware('usage.limit:max_orders_per_month')->name('product-subscriptions.store');
    Route::get('product-subscriptions/{subscription}', [SubscriptionController::class, 'show'])->whereNumber('subscription')->name('product-subscriptions.show');
    Route::patch('product-subscriptions/{subscription}', [SubscriptionController::class, 'update'])->whereNumber('subscription')->name('product-subscriptions.update');
    Route::patch('product-subscriptions/{subscription}/pause', [SubscriptionController::class, 'pause'])->whereNumber('subscription')->name('product-subscriptions.pause');
    Route::patch('product-subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume'])->whereNumber('subscription')->name('product-subscriptions.resume');
    Route::delete('product-subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->whereNumber('subscription')->name('product-subscriptions.destroy');
});

Route::middleware(['tenant.admin', 'feature:product_subscriptions', 'module.notice:product_subscriptions'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('products/{product}/subscription-plans', [AdminSubscriptionPlanController::class, 'index'])->whereNumber('product')->name('products.subscription-plans.index');
    Route::post('products/{product}/subscription-plans', [AdminSubscriptionPlanController::class, 'store'])->whereNumber('product')->name('products.subscription-plans.store');
    Route::delete('products/{product}/subscription-plans/{plan}', [AdminSubscriptionPlanController::class, 'destroy'])->whereNumber(['product', 'plan'])->name('products.subscription-plans.destroy');

    Route::get('product-subscriptions', [AdminSubscriptionController::class, 'index'])->name('product-subscriptions.index');
    Route::get('product-subscriptions/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'product-subscriptions')->name('product-subscriptions.metrics');
    Route::get('product-subscriptions/{subscription}', [AdminSubscriptionController::class, 'show'])->whereNumber('subscription')->name('product-subscriptions.show');
    Route::patch('product-subscriptions/{subscription}/cancel', [AdminSubscriptionController::class, 'cancel'])->whereNumber('subscription')->name('product-subscriptions.cancel');
});
