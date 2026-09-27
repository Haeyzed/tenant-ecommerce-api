<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\Expenses\Http\Controllers\Tenant\Admin\BillerController;
use App\Modules\Expenses\Http\Controllers\Tenant\Admin\ExpenseCategoryController;
use App\Modules\Expenses\Http\Controllers\Tenant\Admin\ExpenseController;
use App\Modules\Expenses\Http\Controllers\Tenant\Admin\IncomeCategoryController;
use App\Modules\Expenses\Http\Controllers\Tenant\Admin\IncomeController;
use Illuminate\Support\Facades\Route;

/*
| Billers, expenses and other income (spec §57.4, §57.8). Feature
| `expenses`; these routes work without `accounting`.
*/

Route::middleware(['tenant.admin', 'feature:expenses', 'module.notice:expenses'])->prefix('admin')->name('tenant.expenses.')->group(function (): void {
    Route::get('billers', [BillerController::class, 'index'])->name('billers.index');
    Route::post('billers', [BillerController::class, 'store'])->name('billers.store');
    Route::patch('billers/{biller}', [BillerController::class, 'update'])->whereNumber('biller')->name('billers.update');
    Route::post('billers/{biller}/deactivate', [BillerController::class, 'deactivate'])->whereNumber('biller')->name('billers.deactivate');

    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
    Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
    Route::patch('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->whereNumber('category')->name('expense-categories.update');

    Route::get('expenses/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'expenses')->name('metrics');
    Route::get('expenses', [ExpenseController::class, 'index'])->name('index');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('store');
    Route::patch('expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('destroy');
    Route::post('expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid'])->whereNumber('expense')->name('mark-paid');

    Route::get('income-categories', [IncomeCategoryController::class, 'index'])->name('income-categories.index');
    Route::post('income-categories', [IncomeCategoryController::class, 'store'])->name('income-categories.store');
    Route::patch('income-categories/{category}', [IncomeCategoryController::class, 'update'])->whereNumber('category')->name('income-categories.update');

    Route::get('income', [IncomeController::class, 'index'])->name('income.index');
    Route::post('income', [IncomeController::class, 'store'])->name('income.store');
    Route::patch('income/{income}', [IncomeController::class, 'update'])->whereNumber('income')->name('income.update');
    Route::delete('income/{income}', [IncomeController::class, 'destroy'])->whereNumber('income')->name('income.destroy');
    Route::post('income/{income}/mark-received', [IncomeController::class, 'markReceived'])->whereNumber('income')->name('income.mark-received');
});
