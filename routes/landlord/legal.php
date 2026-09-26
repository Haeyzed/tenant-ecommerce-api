<?php

declare(strict_types=1);

use App\Modules\Legal\Http\Controllers\Landlord\Admin\LegalAcceptanceController;
use App\Modules\Legal\Http\Controllers\Landlord\Admin\LegalDocumentController as AdminLegalDocumentController;
use App\Modules\Legal\Http\Controllers\Landlord\LegalDocumentController;
use Illuminate\Support\Facades\Route;

/*
| Platform legal documents (spec §9.2, §9.8).
*/

Route::middleware(['landlord.public', 'throttle:auth-sensitive'])->prefix('legal-documents')->name('landlord.legal.public.')->group(function (): void {
    Route::get('current', [LegalDocumentController::class, 'current'])->name('current');
    Route::get('{type}', [LegalDocumentController::class, 'show'])->where('type', '[a-z_]+')->name('show');
});

Route::middleware('landlord.admin')->prefix('admin/legal-documents')->name('landlord.legal.')->group(function (): void {
    Route::get('/', [AdminLegalDocumentController::class, 'index'])->name('index');
    Route::post('/', [AdminLegalDocumentController::class, 'store'])->name('store');
    Route::patch('{document}', [AdminLegalDocumentController::class, 'update'])->name('update');
    Route::post('{document}/publish', [AdminLegalDocumentController::class, 'publish'])->name('publish');
    Route::get('{document}/acceptances', [LegalAcceptanceController::class, 'index'])->name('acceptances.index');
});
