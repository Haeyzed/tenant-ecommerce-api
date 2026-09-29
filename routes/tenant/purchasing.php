<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\PurchaseReturnController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\PurchaseReturnReasonController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\QuotationRequestController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\SupplierController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\SupplierPaymentController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\SupplierProductController;
use App\Modules\Purchasing\Http\Controllers\Tenant\Admin\SupplierQuotationController;
use Illuminate\Support\Facades\Route;

/*
| Suppliers and purchasing (spec §49.7). Feature `purchasing`, back office
| only. Receiving, supplier payments and the return steps stay available
| while the module winds down (config/modules.php). Additions to §49.7:
| supplier details, the suppliers of a product, outstanding balances and
| closing a return.
*/

Route::middleware(['tenant.admin', 'feature:purchasing', 'module.notice:purchasing'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('suppliers/by-product/{product}', [SupplierProductController::class, 'forProduct'])->whereNumber('product')->name('suppliers.by-product');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
    Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->name('suppliers.destroy');
    Route::post('suppliers/{supplier}/products', [SupplierProductController::class, 'store'])->whereNumber('supplier')->name('suppliers.products.store');
    Route::delete('suppliers/{supplier}/products/{product}', [SupplierProductController::class, 'destroy'])->whereNumber(['supplier', 'product'])->name('suppliers.products.destroy');
    Route::get('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'index'])->whereNumber('supplier')->name('suppliers.payments.index');
    Route::post('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->whereNumber('supplier')->name('suppliers.payments.store');
    Route::get('suppliers/{supplier}/balance', [SupplierPaymentController::class, 'supplierBalance'])->whereNumber('supplier')->name('suppliers.balance');

    Route::get('supplier-payments/outstanding', [SupplierPaymentController::class, 'outstanding'])->name('supplier-payments.outstanding');
    Route::patch('supplier-payments/{payment}', [SupplierPaymentController::class, 'update'])->whereNumber('payment')->name('supplier-payments.update');
    Route::delete('supplier-payments/{payment}', [SupplierPaymentController::class, 'destroy'])->whereNumber('payment')->name('supplier-payments.destroy');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('purchase-orders/product-lookup', [PurchaseOrderController::class, 'lookupProducts'])->name('purchase-orders.product-lookup');
    Route::get('purchase-orders/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'purchase-orders')->name('purchase-orders.metrics');
    Route::prefix('purchase-orders/{order}')->whereNumber('order')->name('purchase-orders.')->group(function (): void {
        Route::get('/', [PurchaseOrderController::class, 'show'])->name('show');
        Route::patch('/', [PurchaseOrderController::class, 'update'])->name('update');
        Route::patch('submit', [PurchaseOrderController::class, 'submit'])->name('submit');
        Route::patch('receive', [PurchaseOrderController::class, 'receive'])->name('receive');
        Route::patch('cancel', [PurchaseOrderController::class, 'cancel'])->name('cancel');
        Route::post('duplicate', [PurchaseOrderController::class, 'duplicate'])->name('duplicate');
        Route::get('payments', [SupplierPaymentController::class, 'indexForPurchaseOrder'])->name('payments.index');
        Route::get('balance', [SupplierPaymentController::class, 'purchaseOrderBalance'])->name('balance');
    });

    Route::get('quotation-requests', [QuotationRequestController::class, 'index'])->name('quotation-requests.index');
    Route::post('quotation-requests', [QuotationRequestController::class, 'store'])->name('quotation-requests.store');
    Route::get('quotation-requests/{request}', [QuotationRequestController::class, 'show'])->whereNumber('request')->name('quotation-requests.show');
    Route::post('quotation-requests/{request}/send', [QuotationRequestController::class, 'send'])->whereNumber('request')->name('quotation-requests.send');
    Route::patch('quotation-requests/{request}/cancel', [QuotationRequestController::class, 'cancel'])->whereNumber('request')->name('quotation-requests.cancel');

    Route::patch('supplier-quotations/{quotation}/record', [SupplierQuotationController::class, 'record'])->whereNumber('quotation')->name('supplier-quotations.record');
    Route::patch('supplier-quotations/{quotation}/accept', [SupplierQuotationController::class, 'accept'])->whereNumber('quotation')->name('supplier-quotations.accept');
    Route::patch('supplier-quotations/{quotation}/reject', [SupplierQuotationController::class, 'reject'])->whereNumber('quotation')->name('supplier-quotations.reject');

    Route::get('purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');
    Route::post('purchase-returns', [PurchaseReturnController::class, 'store'])->name('purchase-returns.store');
    Route::prefix('purchase-returns/{return}')->whereNumber('return')->name('purchase-returns.')->group(function (): void {
        Route::get('/', [PurchaseReturnController::class, 'show'])->name('show');
        Route::patch('approve', [PurchaseReturnController::class, 'approve'])->name('approve');
        Route::patch('reject', [PurchaseReturnController::class, 'reject'])->name('reject');
        Route::patch('ship-back', [PurchaseReturnController::class, 'shipBack'])->name('ship-back');
        Route::post('refund', [PurchaseReturnController::class, 'refund'])->name('refund');
        Route::patch('close', [PurchaseReturnController::class, 'close'])->name('close');
    });

    Route::get('purchase-return-reasons', [PurchaseReturnReasonController::class, 'index'])->name('purchase-return-reasons.index');
    Route::post('purchase-return-reasons', [PurchaseReturnReasonController::class, 'store'])->name('purchase-return-reasons.store');
    Route::patch('purchase-return-reasons/{reason}', [PurchaseReturnReasonController::class, 'update'])->whereNumber('reason')->name('purchase-return-reasons.update');
});
