<?php

declare(strict_types=1);

use App\Modules\ModuleNotices\Http\Controllers\Tenant\ModuleNoticeController;
use Illuminate\Support\Facades\Route;

/*
| The current tenant's active module notices (spec §18.5). Not secret.
*/

Route::middleware('tenant.public')->get('module-notices', [ModuleNoticeController::class, 'index'])->name('tenant.module-notices.index');
