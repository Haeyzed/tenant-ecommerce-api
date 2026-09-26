<?php

declare(strict_types=1);

use App\Modules\Lookups\Http\Controllers\LookupController;
use App\Modules\Lookups\Support\LookupRegistry;
use Illuminate\Support\Facades\Route;

/*
| Tenant lookups (spec §45.1, §45.2). No permission beyond authentication
| (Assumption A-29).
*/

Route::middleware('tenant.public')
    ->get('lookups/{key}', [LookupController::class, 'show'])
    ->where('key', '[a-z-]+')
    ->defaults('lookup_context', LookupRegistry::TENANT_PUBLIC)
    ->name('tenant.lookups.public');

Route::middleware('tenant.admin')
    ->withoutMiddleware('permission.derived')
    ->get('admin/lookups/{key}', [LookupController::class, 'show'])
    ->where('key', '[a-z-]+')
    ->defaults('lookup_context', LookupRegistry::TENANT_ADMIN)
    ->name('tenant.lookups.admin');
