<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant payment webhooks (spec §15.6)
|--------------------------------------------------------------------------
|
| POST /api/webhooks/{tenant}/{provider}/{mode} on landlord domains, with the
| tenant identified by path (group tenant.webhooks). Registered by the
| Payments module in routes/tenant/payments-webhooks.php once built.
|
*/

foreach (glob(__DIR__.'/tenant-webhooks/*.php') ?: [] as $file) {
    require $file;
}
