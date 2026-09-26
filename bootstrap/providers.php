<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ModuleServiceProvider::class,
];
