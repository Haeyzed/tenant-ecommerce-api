<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\Tenant\Admin\AccountCategoryController;
use App\Modules\Accounting\Http\Controllers\Tenant\Admin\AccountController;
use App\Modules\Accounting\Http\Controllers\Tenant\Admin\FinancialReportController;
use App\Modules\Accounting\Http\Controllers\Tenant\Admin\FiscalPeriodController;
use App\Modules\Accounting\Http\Controllers\Tenant\Admin\FiscalYearController;
use App\Modules\Accounting\Http\Controllers\Tenant\Admin\JournalEntryController;
use App\Modules\Accounting\Http\Controllers\Tenant\Admin\PostingRequestController;
use Illuminate\Support\Facades\Route;

/*
| The general ledger (spec §57.8). Feature `accounting`; no customer routes.
*/

Route::middleware(['tenant.admin', 'feature:accounting', 'module.notice:accounting'])->prefix('admin/accounting')->name('tenant.accounting.')->group(function (): void {
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
    Route::post('accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->whereNumber('account')->name('accounts.deactivate');

    Route::get('categories', [AccountCategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [AccountCategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [AccountCategoryController::class, 'update'])->whereNumber('category')->name('categories.update');

    Route::get('journal-entries', [JournalEntryController::class, 'index'])->name('journal-entries.index');
    Route::post('journal-entries', [JournalEntryController::class, 'store'])->middleware('idempotency')->name('journal-entries.store');
    Route::get('journal-entries/{entry}', [JournalEntryController::class, 'show'])->whereNumber('entry')->name('journal-entries.show');
    Route::post('journal-entries/{entry}/reverse', [JournalEntryController::class, 'reverse'])->whereNumber('entry')->name('journal-entries.reverse');

    Route::get('posting-requests', [PostingRequestController::class, 'index'])->name('posting-requests.index');
    Route::post('posting-requests/retry', [PostingRequestController::class, 'retry'])->name('posting-requests.retry');

    Route::get('fiscal-years', [FiscalYearController::class, 'index'])->name('fiscal-years.index');
    Route::post('fiscal-years', [FiscalYearController::class, 'store'])->name('fiscal-years.store');
    Route::post('fiscal-years/{year}/close', [FiscalYearController::class, 'close'])->whereNumber('year')->name('fiscal-years.close');
    Route::get('fiscal-years/{year}/periods', [FiscalPeriodController::class, 'index'])->whereNumber('year')->name('fiscal-periods.index');
    Route::post('fiscal-years/{year}/periods', [FiscalPeriodController::class, 'store'])->whereNumber('year')->name('fiscal-periods.store');
    Route::post('fiscal-periods/{period}/close', [FiscalPeriodController::class, 'close'])->whereNumber('period')->name('fiscal-periods.close');
    Route::post('fiscal-periods/{period}/reopen', [FiscalPeriodController::class, 'reopen'])->whereNumber('period')->name('fiscal-periods.reopen');

    Route::get('reports/profit-and-loss', [FinancialReportController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    Route::get('reports/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('reports/general-ledger/{account}', [FinancialReportController::class, 'generalLedger'])->whereNumber('account')->name('reports.general-ledger');
    Route::get('reports/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('reports/cash-flow', [FinancialReportController::class, 'cashFlow'])->name('reports.cash-flow');
});
