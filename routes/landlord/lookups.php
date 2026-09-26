<?php

declare(strict_types=1);

use App\Modules\Lookups\Http\Controllers\LookupController;
use App\Modules\Lookups\Support\LookupRegistry;
use Illuminate\Support\Facades\Route;

/*
| Landlord lookups (spec §45.3). No permission beyond authentication
| (Assumption A-29).
*/

Route::middleware('landlord.public')
    ->get('lookups/{key}', [LookupController::class, 'show'])
    ->where('key', '[a-z-]+')
    ->defaults('lookup_context', LookupRegistry::LANDLORD_PUBLIC)
    ->name('landlord.lookups.public');

Route::middleware('landlord.admin')
    ->withoutMiddleware('permission.derived')
    ->get('admin/lookups/{key}', [LookupController::class, 'show'])
    ->where('key', '[a-z-]+')
    ->defaults('lookup_context', LookupRegistry::LANDLORD_ADMIN)
    ->name('landlord.lookups.admin');
