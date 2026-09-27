<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Tenant\DownloadController;
use App\Modules\Documents\Http\Controllers\Tenant\Admin\BarcodeSettingController;
use App\Modules\Documents\Http\Controllers\Tenant\Admin\InvoiceTemplateController;
use App\Modules\Documents\Http\Controllers\Tenant\Admin\OrderDocumentController as AdminOrderDocumentController;
use App\Modules\Documents\Http\Controllers\Tenant\Admin\PrintBarcodeController;
use App\Modules\Documents\Http\Controllers\Tenant\Admin\ReceiptPrinterController;
use App\Modules\Documents\Http\Controllers\Tenant\OrderDocumentController;
use Illuminate\Support\Facades\Route;

/*
| Documents and printing (spec §43.7), order documents (§39.7) and digital
| downloads (§28.4). Core commerce.
*/

Route::middleware(['tenant.storefront', 'module.notice:core'])->name('tenant.documents.')->group(function (): void {
    Route::get('orders/{order}/invoice', [OrderDocumentController::class, 'invoice'])->whereNumber('order')->name('invoice');
    Route::get('account/downloads', [DownloadController::class, 'index'])->name('downloads.index');
    Route::get('downloads/{grant}', [DownloadController::class, 'show'])->whereNumber('grant')->name('downloads.show');
});

Route::middleware(['tenant.admin', 'module.notice:core'])->prefix('admin')->name('tenant.documents.admin.')->group(function (): void {
    Route::get('orders/{order}/invoice', [AdminOrderDocumentController::class, 'invoice'])->whereNumber('order')->name('invoice');
    Route::get('orders/{order}/packing-slip', [AdminOrderDocumentController::class, 'packingSlip'])->whereNumber('order')->name('packing-slip');

    Route::get('invoice-templates', [InvoiceTemplateController::class, 'index'])->name('invoice-templates.index');
    Route::post('invoice-templates', [InvoiceTemplateController::class, 'store'])->name('invoice-templates.store');
    Route::patch('invoice-templates/{template}', [InvoiceTemplateController::class, 'update'])->whereNumber('template')->name('invoice-templates.update');
    Route::delete('invoice-templates/{template}', [InvoiceTemplateController::class, 'destroy'])->whereNumber('template')->name('invoice-templates.destroy');
    Route::patch('invoice-templates/{template}/set-default', [InvoiceTemplateController::class, 'setDefault'])->whereNumber('template')->name('invoice-templates.set-default');

    Route::get('barcode-settings', [BarcodeSettingController::class, 'index'])->name('barcode-settings.index');
    Route::post('barcode-settings', [BarcodeSettingController::class, 'store'])->name('barcode-settings.store');
    Route::post('barcode-settings/generate', [BarcodeSettingController::class, 'generate'])->name('barcode-settings.generate');
    Route::patch('barcode-settings/{setting}', [BarcodeSettingController::class, 'update'])->whereNumber('setting')->name('barcode-settings.update');
    Route::delete('barcode-settings/{setting}', [BarcodeSettingController::class, 'destroy'])->whereNumber('setting')->name('barcode-settings.destroy');
    Route::patch('barcode-settings/{setting}/set-default', [BarcodeSettingController::class, 'setDefault'])->whereNumber('setting')->name('barcode-settings.set-default');

    Route::get('print-barcode/search', [PrintBarcodeController::class, 'search'])->name('print-barcode.search');
    Route::post('print-barcode/generate', [PrintBarcodeController::class, 'generate'])->name('print-barcode.generate');

    Route::get('receipt-printers', [ReceiptPrinterController::class, 'index'])->name('receipt-printers.index');
    Route::post('receipt-printers', [ReceiptPrinterController::class, 'store'])->name('receipt-printers.store');
    Route::patch('receipt-printers/{printer}', [ReceiptPrinterController::class, 'update'])->whereNumber('printer')->name('receipt-printers.update');
    Route::delete('receipt-printers/{printer}', [ReceiptPrinterController::class, 'destroy'])->whereNumber('printer')->name('receipt-printers.destroy');
    Route::patch('receipt-printers/{printer}/activate', [ReceiptPrinterController::class, 'activate'])->whereNumber('printer')->name('receipt-printers.activate');
    Route::patch('receipt-printers/{printer}/deactivate', [ReceiptPrinterController::class, 'deactivate'])->whereNumber('printer')->name('receipt-printers.deactivate');
});
