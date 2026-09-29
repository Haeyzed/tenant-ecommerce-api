<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\SalesQuotations\Http\Controllers\Tenant\Admin\SalesQuotationController;
use App\Modules\SalesQuotations\Http\Controllers\Tenant\Admin\SalesQuotationRequestController;
use App\Modules\SalesQuotations\Http\Controllers\Tenant\QuotationRequestController;
use Illuminate\Support\Facades\Route;

/*
| Sales quotations (spec §53.3). Feature `sales_quotations`. The customer
| routes are unrelated to the supplier-side admin/quotation-requests
| (§49.7). The {request} placeholder of §53.3 is {quotationRequest} here.
*/

Route::middleware(['tenant.customer', 'feature:sales_quotations'])->name('tenant.customer.')->group(function (): void {
    Route::get('quotation-requests', [QuotationRequestController::class, 'index'])->name('quotation-requests.index');
    Route::post('quotation-requests', [QuotationRequestController::class, 'store'])->name('quotation-requests.store');
    Route::get('quotation-requests/{quotationRequest}', [QuotationRequestController::class, 'show'])->whereNumber('quotationRequest')->name('quotation-requests.show');
    Route::post('quotation-requests/{quotationRequest}/accept', [QuotationRequestController::class, 'accept'])->whereNumber('quotationRequest')->name('quotation-requests.accept');
    Route::post('quotation-requests/{quotationRequest}/reject', [QuotationRequestController::class, 'reject'])->whereNumber('quotationRequest')->name('quotation-requests.reject');
});

Route::middleware(['tenant.admin', 'feature:sales_quotations', 'module.notice:sales_quotations'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('sales-quotation-requests', [SalesQuotationRequestController::class, 'index'])->name('sales-quotation-requests.index');
    Route::get('sales-quotation-requests/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'sales-quotation-requests')->name('sales-quotation-requests.metrics');
    Route::post('sales-quotation-requests', [SalesQuotationRequestController::class, 'store'])->name('sales-quotation-requests.store');
    Route::get('sales-quotation-requests/{quotationRequest}', [SalesQuotationRequestController::class, 'show'])->whereNumber('quotationRequest')->name('sales-quotation-requests.show');
    Route::post('sales-quotation-requests/{quotationRequest}/send', [SalesQuotationRequestController::class, 'send'])->whereNumber('quotationRequest')->name('sales-quotation-requests.send');
    Route::post('sales-quotation-requests/{quotationRequest}/cancel', [SalesQuotationRequestController::class, 'cancel'])->whereNumber('quotationRequest')->name('sales-quotation-requests.cancel');

    Route::post('sales-quotations/{quotation}/accept', [SalesQuotationController::class, 'accept'])->whereNumber('quotation')->name('sales-quotations.accept');
    Route::post('sales-quotations/{quotation}/reject', [SalesQuotationController::class, 'reject'])->whereNumber('quotation')->name('sales-quotations.reject');
});
