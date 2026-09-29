<?php

declare(strict_types=1);

use App\Modules\Access\Support\RoutePermissions;
use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\AppraisalController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\AppraisalTemplateController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\AttendanceController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\DepartmentController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\DepartmentSettingsController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\DocumentTypeController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\EmployeeController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\EmployeeDocumentController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\HrSettingsController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\JobApplicationController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\JobPostingController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\LeaveBalanceController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\LeaveRequestController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\LeaveTypeController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\OvertimeController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\PayrollItemController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\PayrollRunController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\PayslipController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\RosterController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\SalaryController;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\ShiftController;
use App\Modules\Hr\Http\Controllers\Tenant\CareerController;
use Illuminate\Support\Facades\Route;

/*
| Human resources (spec §58.9). Feature `hr`; payroll routes carry
| `hr_payroll` and recruitment routes `hr_recruitment` instead (a submodule
| is enabled only while hr is). Self-service rows (own attendance, own leave,
| acknowledging one's own appraisal) skip the derived permission; the
| permission for acting on someone else is declared on the route.
| Additions: the document and résumé downloads.
*/

Route::middleware(['tenant.public', 'feature:hr_recruitment'])->prefix('careers')->name('tenant.public.careers.')->group(function (): void {
    $slug = '[A-Za-z0-9-]+';

    Route::get('jobs', [CareerController::class, 'index'])->name('jobs.index');
    Route::get('jobs/{slug}', [CareerController::class, 'show'])->where('slug', $slug)->name('jobs.show');
    Route::post('jobs/{slug}/apply', [CareerController::class, 'apply'])->where('slug', $slug)->middleware('throttle:auth-sensitive')->name('jobs.apply');
});

Route::middleware(['tenant.admin', 'feature:hr', 'module.notice:hr'])->prefix('admin/hr')->name('tenant.admin.hr.')->group(function (): void {
    Route::get('settings', [HrSettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [HrSettingsController::class, 'update'])->name('settings.update');

    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::patch('departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('departments.update');
    Route::post('departments/{department}/assign-employee', [DepartmentController::class, 'assignEmployee'])->whereNumber('department')->name('departments.assign-employee');
    Route::get('departments/{department}/settings', [DepartmentSettingsController::class, 'show'])->whereNumber('department')->name('departments.settings.show');
    Route::put('departments/{department}/settings', [DepartmentSettingsController::class, 'update'])->whereNumber('department')->name('departments.settings.update');
    Route::delete('departments/{department}/settings', [DepartmentSettingsController::class, 'destroy'])->whereNumber('department')->name('departments.settings.destroy');

    Route::get('document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
    Route::post('document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
    Route::patch('document-types/{documentType}', [DocumentTypeController::class, 'update'])->whereNumber('documentType')->name('document-types.update');
    Route::delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->whereNumber('documentType')->name('document-types.destroy');

    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'hr-employees')->name('employees.metrics');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware('usage.limit:max_employees')->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee')->name('employees.destroy');
    Route::post('employees/{employee}/deactivate', [EmployeeController::class, 'deactivate'])->whereNumber('employee')->name('employees.deactivate');
    Route::post('employees/{employee}/reactivate', [EmployeeController::class, 'reactivate'])->whereNumber('employee')->middleware('usage.limit:max_employees')->name('employees.reactivate');
    Route::post('employees/{employee}/link-user', [EmployeeController::class, 'linkUser'])->whereNumber('employee')->name('employees.link-user');
    Route::post('employees/{employee}/unlink-user', [EmployeeController::class, 'unlinkUser'])->whereNumber('employee')->name('employees.unlink-user');

    Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->whereNumber('employee')->name('employees.documents.index');
    Route::post('employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->whereNumber('employee')->name('employees.documents.store');
    Route::get('employees/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download'])->whereNumber(['employee', 'document'])->name('employees.documents.download');
    Route::delete('employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->whereNumber(['employee', 'document'])->name('employees.documents.destroy');
    Route::get('documents/expiring', [EmployeeDocumentController::class, 'expiring'])->name('documents.expiring');

    Route::withoutMiddleware('permission.derived')->group(function (): void {
        Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn'])->defaults(RoutePermissions::ACTING_PERMISSION, AttendanceController::RECORD)->name('attendance.clock-in');
        Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut'])->defaults(RoutePermissions::ACTING_PERMISSION, AttendanceController::RECORD)->name('attendance.clock-out');
        Route::post('leave-requests', [LeaveRequestController::class, 'store'])->defaults(RoutePermissions::ACTING_PERMISSION, LeaveRequestController::CREATE)->name('leave-requests.store');
        Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('leaveRequest')
            ->defaults(RoutePermissions::ACTING_PERMISSION, LeaveRequestController::CANCEL)->name('leave-requests.cancel');
        Route::post('appraisals/{appraisal}/acknowledge', [AppraisalController::class, 'acknowledge'])->whereNumber('appraisal')
            ->defaults(RoutePermissions::ACTING_PERMISSION, AppraisalController::ACKNOWLEDGE)->name('appraisals.acknowledge');
        Route::get('my-shifts', [RosterController::class, 'mine'])->defaults(RoutePermissions::ACTING_PERMISSION, RosterController::VIEW)->name('roster.mine');
    });
    Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
    Route::get('employees/{employee}/attendance', [AttendanceController::class, 'forEmployee'])->whereNumber('employee')->name('employees.attendance');

    // Shifts, the roster and overtime (§58.3a).
    Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::patch('shifts/{shift}', [ShiftController::class, 'update'])->whereNumber('shift')->name('shifts.update');
    Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])->whereNumber('shift')->name('shifts.destroy');
    Route::get('roster', [RosterController::class, 'index'])->name('roster.index');
    Route::post('roster/assign', [RosterController::class, 'assign'])->name('roster.assign');
    Route::post('roster/unassign', [RosterController::class, 'unassign'])->name('roster.unassign');
    Route::get('overtime', [OvertimeController::class, 'index'])->name('overtime.index');
    Route::post('attendance/{attendance}/overtime/approve', [OvertimeController::class, 'approve'])->whereNumber('attendance')->name('overtime.approve');
    Route::post('attendance/{attendance}/overtime/reject', [OvertimeController::class, 'reject'])->whereNumber('attendance')->name('overtime.reject');

    Route::get('leave-types', [LeaveTypeController::class, 'index'])->name('leave-types.index');
    Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
    Route::patch('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->whereNumber('leaveType')->name('leave-types.update');
    Route::get('employees/{employee}/leave-balances', [LeaveBalanceController::class, 'index'])->whereNumber('employee')->name('employees.leave-balances');
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->whereNumber('leaveRequest')->name('leave-requests.approve');
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->whereNumber('leaveRequest')->name('leave-requests.reject');

    Route::get('appraisal-templates', [AppraisalTemplateController::class, 'index'])->name('appraisal-templates.index');
    Route::post('appraisal-templates', [AppraisalTemplateController::class, 'store'])->name('appraisal-templates.store');
    Route::patch('appraisal-templates/{template}', [AppraisalTemplateController::class, 'update'])->whereNumber('template')->name('appraisal-templates.update');
    Route::delete('appraisal-templates/{template}', [AppraisalTemplateController::class, 'destroy'])->whereNumber('template')->name('appraisal-templates.destroy');
    Route::post('appraisal-templates/{template}/criteria', [AppraisalTemplateController::class, 'addCriterion'])->whereNumber('template')->name('appraisal-templates.criteria.store');
    Route::delete('appraisal-template-criteria/{criterion}', [AppraisalTemplateController::class, 'removeCriterion'])->whereNumber('criterion')->name('appraisal-template-criteria.destroy');
    Route::get('employees/{employee}/appraisals', [AppraisalController::class, 'index'])->whereNumber('employee')->name('employees.appraisals.index');
    Route::post('employees/{employee}/appraisals', [AppraisalController::class, 'store'])->whereNumber('employee')->name('employees.appraisals.store');
    Route::post('appraisals/{appraisal}/scores', [AppraisalController::class, 'score'])->whereNumber('appraisal')->name('appraisals.scores');
    Route::post('appraisals/{appraisal}/submit', [AppraisalController::class, 'submit'])->whereNumber('appraisal')->name('appraisals.submit');
});

Route::middleware(['tenant.admin', 'feature:hr_payroll', 'module.notice:hr_payroll'])->prefix('admin/hr')->name('tenant.admin.hr.')->group(function (): void {
    Route::get('employees/{employee}/salary', [SalaryController::class, 'index'])->whereNumber('employee')->name('employees.salary.index');
    Route::post('employees/{employee}/salary', [SalaryController::class, 'store'])->whereNumber('employee')->name('employees.salary.store');

    Route::get('payroll-runs', [PayrollRunController::class, 'index'])->name('payroll-runs.index');
    Route::post('payroll-runs', [PayrollRunController::class, 'store'])->name('payroll-runs.store');
    Route::get('payroll-runs/{run}', [PayrollRunController::class, 'show'])->whereNumber('run')->name('payroll-runs.show');
    Route::post('payroll-runs/{run}/generate-items', [PayrollRunController::class, 'generateItems'])->whereNumber('run')->name('payroll-runs.generate-items');
    Route::post('payroll-runs/{run}/finalize', [PayrollRunController::class, 'finalize'])->whereNumber('run')->name('payroll-runs.finalize');
    Route::post('payroll-runs/{run}/mark-paid', [PayrollRunController::class, 'markPaid'])->whereNumber('run')->middleware('idempotency')->name('payroll-runs.mark-paid');
    Route::get('payroll-runs/{run}/items', [PayrollItemController::class, 'index'])->whereNumber('run')->name('payroll-runs.items');

    Route::get('payroll-items/{item}', [PayrollItemController::class, 'show'])->whereNumber('item')->name('payroll-items.show');
    Route::post('payroll-items/{item}/lines', [PayrollItemController::class, 'addLine'])->whereNumber('item')->name('payroll-items.lines');
    Route::post('payroll-items/{item}/recalculate', [PayrollItemController::class, 'recalculate'])->whereNumber('item')->name('payroll-items.recalculate');
    Route::post('payroll-items/{item}/mark-paid', [PayrollItemController::class, 'markPaid'])->whereNumber('item')->middleware('idempotency')->name('payroll-items.mark-paid');
    Route::delete('payroll-item-lines/{line}', [PayrollItemController::class, 'removeLine'])->whereNumber('line')->name('payroll-item-lines.destroy');

    Route::get('employees/{employee}/payslips/{run}', [PayslipController::class, 'show'])->whereNumber(['employee', 'run'])->name('employees.payslips.show');
    Route::get('employees/{employee}/payroll-history', [PayslipController::class, 'history'])->whereNumber('employee')->name('employees.payroll-history');
});

Route::middleware(['tenant.admin', 'feature:hr_recruitment', 'module.notice:hr_recruitment'])->prefix('admin/hr')->name('tenant.admin.hr.')->group(function (): void {
    Route::get('job-postings', [JobPostingController::class, 'index'])->name('job-postings.index');
    Route::post('job-postings', [JobPostingController::class, 'store'])->name('job-postings.store');
    Route::patch('job-postings/{posting}', [JobPostingController::class, 'update'])->whereNumber('posting')->name('job-postings.update');
    Route::post('job-postings/{posting}/publish', [JobPostingController::class, 'publish'])->whereNumber('posting')->name('job-postings.publish');
    Route::post('job-postings/{posting}/close', [JobPostingController::class, 'close'])->whereNumber('posting')->name('job-postings.close');
    Route::delete('job-postings/{posting}', [JobPostingController::class, 'destroy'])->whereNumber('posting')->name('job-postings.destroy');
    Route::get('job-postings/{posting}/applications', [JobApplicationController::class, 'index'])->whereNumber('posting')->name('job-postings.applications');

    Route::patch('applications/{application}/status', [JobApplicationController::class, 'updateStatus'])->whereNumber('application')->name('applications.status');
    Route::post('applications/{application}/notes', [JobApplicationController::class, 'addNote'])->whereNumber('application')->name('applications.notes');
    Route::get('applications/{application}/resume', [JobApplicationController::class, 'resume'])->whereNumber('application')->name('applications.resume');
    Route::post('applications/{application}/convert-to-employee', [JobApplicationController::class, 'convertToEmployee'])->whereNumber('application')
        ->middleware('usage.limit:max_employees')->name('applications.convert-to-employee');
});
