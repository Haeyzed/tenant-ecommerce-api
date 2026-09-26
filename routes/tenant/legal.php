<?php

declare(strict_types=1);

use App\Modules\Legal\Http\Controllers\Tenant\Admin\LegalAcceptanceController;
use Illuminate\Support\Facades\Route;

/*
| The owner's acceptance of re-acceptance versions (spec §9.8). Derived
| permission legal-acceptances.create, seeded only to owner.
*/

Route::middleware('tenant.admin')->post('admin/legal-acceptances', [LegalAcceptanceController::class, 'store'])->name('tenant.legal-acceptances.store');
