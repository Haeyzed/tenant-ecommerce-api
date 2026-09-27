<?php

declare(strict_types=1);

use App\Modules\Approvals\Http\Controllers\Tenant\Admin\ApprovalController;
use App\Modules\Approvals\Http\Controllers\Tenant\Admin\WorkflowController;
use App\Modules\Approvals\Http\Controllers\Tenant\Admin\WorkflowStepController;
use Illuminate\Support\Facades\Route;

/*
| The approval workflow engine (spec §60.5). Feature `approval_workflows`.
| Configuration uses derived permissions; acting on approvals has none
| (eligibility is checked per step). approve and reject stay available
| while the module winds down (config/modules.php), so pending requests
| can still be finished.
*/

Route::middleware(['tenant.admin', 'feature:approval_workflows', 'module.notice:approval_workflows'])->prefix('admin')->group(function (): void {
    Route::name('tenant.approvals.workflows.')->group(function (): void {
        Route::get('approval-workflows', [WorkflowController::class, 'index'])->name('index');
        Route::post('approval-workflows', [WorkflowController::class, 'store'])->name('store');
        Route::get('approval-workflows/{workflow}', [WorkflowController::class, 'show'])->whereNumber('workflow')->name('show');
        Route::patch('approval-workflows/{workflow}', [WorkflowController::class, 'update'])->whereNumber('workflow')->name('update');
        Route::delete('approval-workflows/{workflow}', [WorkflowController::class, 'destroy'])->whereNumber('workflow')->name('destroy');
        Route::post('approval-workflows/{workflow}/deactivate', [WorkflowController::class, 'deactivate'])->whereNumber('workflow')->name('deactivate');
        Route::post('approval-workflows/{workflow}/steps', [WorkflowStepController::class, 'store'])->whereNumber('workflow')->name('steps.store');
        Route::patch('approval-workflows/{workflow}/steps/reorder', [WorkflowStepController::class, 'reorder'])->whereNumber('workflow')->name('steps.reorder');
        Route::delete('approval-workflows/{workflow}/steps/{step}', [WorkflowStepController::class, 'destroy'])->whereNumber(['workflow', 'step'])->name('steps.destroy');
    });

    Route::withoutMiddleware('permission.derived')->name('tenant.admin.approvals.')->group(function (): void {
        Route::get('approvals/my-pending', [ApprovalController::class, 'myPending'])->name('my-pending');
        Route::get('approvals/{approval}', [ApprovalController::class, 'show'])->whereNumber('approval')->name('show');
        Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber('approval')->name('approve');
        Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->whereNumber('approval')->name('reject');
    });
});
