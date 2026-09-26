<?php

declare(strict_types=1);

use App\Modules\Seo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant routes (spec §70.2)
|--------------------------------------------------------------------------
|
| Every host that is not a landlord domain. Each tenant module has its own
| file in routes/tenant; each route names its group (tenant.public,
| tenant.storefront, tenant.customer, tenant.seller, tenant.driver,
| tenant.admin), which fixes its middleware stack (spec §70.3).
|
*/

// The storefront sitemap, outside /api (spec §30.2).
Route::middleware('tenant.public')->get('sitemap.xml', SitemapController::class)->name('tenant.sitemap');

Route::prefix('api')->group(function (): void {
    foreach (glob(__DIR__.'/tenant/*.php') ?: [] as $file) {
        require $file;
    }
});
