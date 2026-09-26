<?php

declare(strict_types=1);

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

Route::prefix('api')->group(function (): void {
    foreach (glob(__DIR__.'/tenant/*.php') ?: [] as $file) {
        require $file;
    }
});
