<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Tenant\Admin\ResourceMetricsController;
use App\Modules\Projects\Http\Controllers\Tenant\Admin\ProjectCategoryController;
use App\Modules\Projects\Http\Controllers\Tenant\Admin\ProjectController;
use App\Modules\Projects\Http\Controllers\Tenant\Admin\ProjectTaskController;
use Illuminate\Support\Facades\Route;

/*
| Project management (spec §63.4). Feature `project_management`.
*/

Route::middleware(['tenant.admin', 'feature:project_management', 'module.notice:project_management'])->prefix('admin')->name('tenant.admin.')->group(function (): void {
    Route::get('project-categories', [ProjectCategoryController::class, 'index'])->name('project-categories.index');
    Route::post('project-categories', [ProjectCategoryController::class, 'store'])->name('project-categories.store');
    Route::patch('project-categories/{category}', [ProjectCategoryController::class, 'update'])->whereNumber('category')->name('project-categories.update');
    Route::delete('project-categories/{category}', [ProjectCategoryController::class, 'destroy'])->whereNumber('category')->name('project-categories.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/metrics', [ResourceMetricsController::class, 'metrics'])->defaults('metrics_resource', 'projects')->name('projects.metrics');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->whereNumber('project')->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->name('projects.destroy');
    Route::patch('projects/{project}/assign-employees', [ProjectController::class, 'assignEmployees'])->whereNumber('project')->name('projects.assign-employees');
    Route::patch('projects/{project}/status', [ProjectController::class, 'updateStatus'])->whereNumber('project')->name('projects.status');

    Route::get('projects/{project}/tasks', [ProjectTaskController::class, 'index'])->whereNumber('project')->name('projects.tasks.index');
    Route::post('projects/{project}/tasks', [ProjectTaskController::class, 'store'])->whereNumber('project')->name('projects.tasks.store');
    Route::patch('projects/{project}/tasks/{task}', [ProjectTaskController::class, 'update'])->whereNumber(['project', 'task'])->name('projects.tasks.update');
    Route::delete('projects/{project}/tasks/{task}', [ProjectTaskController::class, 'destroy'])->whereNumber(['project', 'task'])->name('projects.tasks.destroy');
    Route::patch('projects/{project}/tasks/{task}/status', [ProjectTaskController::class, 'updateStatus'])->whereNumber(['project', 'task'])->name('projects.tasks.status');
});
