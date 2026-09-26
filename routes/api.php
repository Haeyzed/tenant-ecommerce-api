<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Landlord routes (spec §70.2)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the landlord-domain group with the
| "/api" prefix. Each landlord module has its own file in routes/landlord.
|
*/

foreach (glob(__DIR__.'/landlord/*.php') ?: [] as $file) {
    require $file;
}
