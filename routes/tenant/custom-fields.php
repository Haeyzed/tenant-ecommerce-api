<?php

declare(strict_types=1);

use App\Modules\CustomFields\Http\Controllers\Tenant\Admin\CustomFieldController;
use App\Modules\CustomFields\Http\Controllers\Tenant\CustomFieldMediaController;
use App\Modules\CustomFields\Http\Controllers\Tenant\StorefrontCustomFieldController;
use Illuminate\Support\Facades\Route;

/*
| Custom fields (spec §23.7). No feature key: module-entity rules (§23.4)
| are applied by the service.
*/

Route::middleware('tenant.admin')->prefix('admin')->name('tenant.custom-fields.')->group(function (): void {
    Route::get('custom-fields/entities', [CustomFieldController::class, 'entities'])->name('entities');
    Route::put('custom-fields/reorder', [CustomFieldController::class, 'reorder'])->name('reorder');
    Route::get('custom-fields', [CustomFieldController::class, 'index'])->name('index');
    Route::post('custom-fields', [CustomFieldController::class, 'store'])->middleware('usage.limit:max_custom_fields')->name('store');
    Route::get('custom-fields/{field}', [CustomFieldController::class, 'show'])->whereNumber('field')->name('show');
    Route::patch('custom-fields/{field}', [CustomFieldController::class, 'update'])->whereNumber('field')->name('update');
    Route::post('custom-fields/{field}/deactivate', [CustomFieldController::class, 'deactivate'])->whereNumber('field')->name('deactivate');
    Route::post('custom-fields/{field}/reactivate', [CustomFieldController::class, 'reactivate'])->whereNumber('field')->middleware('usage.limit:max_custom_fields')->name('reactivate');
    Route::delete('custom-fields/{field}', [CustomFieldController::class, 'destroy'])->whereNumber('field')->name('destroy');
});

Route::middleware('tenant.public')
    ->get('storefront/custom-fields', [StorefrontCustomFieldController::class, 'index'])
    ->name('tenant.storefront.custom-fields.index');

// Private custom-field files, by short-lived signed URL only.
Route::middleware(['tenant.public', 'signed'])
    ->get('custom-field-files/{media}', CustomFieldMediaController::class)
    ->whereNumber('media')
    ->name('tenant.custom-fields.media');
