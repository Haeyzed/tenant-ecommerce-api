<?php

declare(strict_types=1);

use App\Modules\Cart\CartServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Documents\DocumentsServiceProvider;
use App\Modules\GiftCards\GiftCardsServiceProvider;
use App\Modules\Installments\InstallmentsServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Pos\PosServiceProvider;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Modules\Purchasing\PurchasingServiceProvider;
use App\Modules\Returns\ReturnsServiceProvider;
use App\Modules\Reviews\ReviewsServiceProvider;
use App\Modules\RewardPoints\RewardPointsServiceProvider;
use App\Modules\SalesAgents\SalesAgentsServiceProvider;
use App\Providers\ApiDocsServiceProvider;
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
    ReturnsServiceProvider::class,
    ReviewsServiceProvider::class,
    DocumentsServiceProvider::class,
    PurchasingServiceProvider::class,
    GiftCardsServiceProvider::class,
    InstallmentsServiceProvider::class,
    RewardPointsServiceProvider::class,
    PosServiceProvider::class,
    SalesAgentsServiceProvider::class,
    ApiDocsServiceProvider::class,
];
