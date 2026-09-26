<?php

declare(strict_types=1);

use App\Modules\Cart\CartServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    ModuleServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    PromotionsServiceProvider::class,
    CartServiceProvider::class,
    OrdersServiceProvider::class,
];
