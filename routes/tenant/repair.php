<?php

declare(strict_types=1);

use App\Modules\Repair\Http\Controllers\Tenant\Admin\RepairJobController as AdminRepairJobController;
use App\Modules\Repair\Http\Controllers\Tenant\Admin\RepairJobLaborController;
use App\Modules\Repair\Http\Controllers\Tenant\Admin\RepairJobPartController;
use App\Modules\Repair\Http\Controllers\Tenant\RepairJobController;
use Illuminate\Support\Facades\Route;

/*
| Repair (spec §67.4). Feature `repair`. Status changes stay open while the
| module winds down, so items in the workshop can be finished and handed
| back.
*/

Route::middleware(['tenant.customer', 'feature:repair'])->prefix('account')->name('tenant.customer.account.')->group(function (): void {
    Route::get('repair-jobs', [RepairJobController::class, 'index'])->name('repair-jobs.index');
    Route::get('repair-jobs/{job}', [RepairJobController::class, 'show'])->whereNumber('job')->name('repair-jobs.show');
    Route::patch('repair-jobs/{job}/approve', [RepairJobController::class, 'approve'])->whereNumber('job')->name('repair-jobs.approve');
});

Route::middleware(['tenant.admin', 'feature:repair', 'module.notice:repair'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('repair-jobs', [AdminRepairJobController::class, 'index'])->name('repair-jobs.index');
    Route::post('repair-jobs', [AdminRepairJobController::class, 'store'])->name('repair-jobs.store');
    Route::get('repair-jobs/{job}', [AdminRepairJobController::class, 'show'])->whereNumber('job')->name('repair-jobs.show');
    Route::patch('repair-jobs/{job}/diagnosis', [AdminRepairJobController::class, 'diagnosis'])->whereNumber('job')->name('repair-jobs.diagnosis');
    Route::patch('repair-jobs/{job}/approval', [AdminRepairJobController::class, 'recordApproval'])->whereNumber('job')->name('repair-jobs.approval');
    Route::patch('repair-jobs/{job}/status', [AdminRepairJobController::class, 'updateStatus'])->whereNumber('job')->name('repair-jobs.status');
    Route::post('repair-jobs/{job}/parts', [RepairJobPartController::class, 'store'])->whereNumber('job')->name('repair-jobs.parts.store');
    Route::delete('repair-jobs/{job}/parts/{part}', [RepairJobPartController::class, 'destroy'])->whereNumber(['job', 'part'])->name('repair-jobs.parts.destroy');
    Route::post('repair-jobs/{job}/labor', [RepairJobLaborController::class, 'store'])->whereNumber('job')->name('repair-jobs.labor.store');
    Route::delete('repair-jobs/{job}/labor/{labor}', [RepairJobLaborController::class, 'destroy'])->whereNumber(['job', 'labor'])->name('repair-jobs.labor.destroy');
    Route::post('repair-jobs/{job}/generate-invoice', [AdminRepairJobController::class, 'generateInvoice'])->whereNumber('job')->middleware('usage.limit:max_orders_per_month')->name('repair-jobs.generate-invoice');
    Route::patch('repair-jobs/{job}/picked-up', [AdminRepairJobController::class, 'pickedUp'])->whereNumber('job')->name('repair-jobs.picked-up');
});
