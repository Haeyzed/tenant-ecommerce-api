<?php

declare(strict_types=1);

use App\Modules\Checkout\Http\Controllers\Tenant\CheckoutController;
use App\Modules\Orders\Http\Controllers\Tenant\Admin\OrderController as AdminOrderController;
use App\Modules\Orders\Http\Controllers\Tenant\Admin\OrderItemController;
use App\Modules\Orders\Http\Controllers\Tenant\OrderController;
use App\Modules\Payments\Http\Controllers\Tenant\Admin\OrderBalanceController;
use App\Modules\Payments\Http\Controllers\Tenant\Admin\OrderPaymentController as AdminOrderPaymentController;
use App\Modules\Payments\Http\Controllers\Tenant\Admin\OrderPaymentLedgerController;
use App\Modules\Payments\Http\Controllers\Tenant\OrderPaymentController;
use Illuminate\Support\Facades\Route;

/*
| Checkout, orders and order payments (spec §38.7, §39.7, §40.7). Core
| commerce. Documents (invoice, packing slip) arrive with §43; shipments
| and tracking with §36.
*/

Route::middleware(['tenant.storefront', 'module.notice:core'])->name('tenant.orders.')->group(function (): void {
    Route::post('orders', [CheckoutController::class, 'store'])->middleware(['idempotency', 'usage.limit:max_orders_per_month'])->name('store');
    Route::get('orders', [OrderController::class, 'index'])->name('index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('show');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order')->name('cancel');
    Route::post('orders/{order}/pay', [OrderPaymentController::class, 'store'])->whereNumber('order')->middleware('idempotency')->name('pay');
    Route::post('payments/verify', [OrderPaymentController::class, 'verify'])->name('payments.verify');
    Route::get('payment-methods', [OrderPaymentController::class, 'methods'])->name('payment-methods');
});

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.orders.admin.')->group(function (): void {
    Route::get('orders', [AdminOrderController::class, 'index'])->name('index');
    Route::get('orders/{order}', [AdminOrderController::class, 'show'])->whereNumber('order')->name('show');
    Route::patch('orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->whereNumber('order')->name('status');
    Route::post('orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('order')->name('cancel');
    Route::post('orders/{order}/refund', [AdminOrderController::class, 'refund'])->whereNumber('order')->middleware('idempotency')->name('refund');
    Route::post('orders/{order}/duplicate', [AdminOrderController::class, 'duplicate'])->whereNumber('order')->middleware('usage.limit:max_orders_per_month')->name('duplicate');
    Route::patch('orders/{order}/items/{item}/warehouse', [OrderItemController::class, 'changeWarehouse'])->whereNumber(['order', 'item'])->name('items.warehouse');

    Route::get('orders/{order}/payments', [OrderPaymentLedgerController::class, 'index'])->whereNumber('order')->name('payments.index');
    Route::post('orders/{order}/payments', [OrderPaymentLedgerController::class, 'store'])->whereNumber('order')->middleware('idempotency')->name('payments.store');
    Route::get('orders/{order}/balance', [OrderBalanceController::class, 'index'])->whereNumber('order')->name('balance');
    Route::get('order-payments', [AdminOrderPaymentController::class, 'index'])->name('order-payments.index');
    Route::patch('order-payments/{payment}', [OrderPaymentLedgerController::class, 'update'])->whereNumber('payment')->name('order-payments.update');
    Route::delete('order-payments/{payment}', [OrderPaymentLedgerController::class, 'destroy'])->whereNumber('payment')->name('order-payments.destroy');
    Route::post('order-payments/{payment}/resolve-refund', [OrderPaymentLedgerController::class, 'resolveRefund'])->whereNumber('payment')->name('order-payments.resolve-refund');
});
