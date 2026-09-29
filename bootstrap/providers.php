<?php

declare(strict_types=1);

use App\Modules\BackInStock\BackInStockServiceProvider;
use App\Modules\Cart\CartServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Documents\DocumentsServiceProvider;
use App\Modules\GiftCards\GiftCardsServiceProvider;
use App\Modules\Hr\HrServiceProvider;
use App\Modules\Installments\InstallmentsServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Marketplace\MarketplaceServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Pos\PosServiceProvider;
use App\Modules\ProductSubscriptions\ProductSubscriptionsServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Modules\Purchasing\PurchasingServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Returns\ReturnsServiceProvider;
use App\Modules\Reviews\ReviewsServiceProvider;
use App\Modules\RewardPoints\RewardPointsServiceProvider;
use App\Modules\SalesAgents\SalesAgentsServiceProvider;
use App\Modules\SalesQuotations\SalesQuotationsServiceProvider;
use App\Modules\Support\SupportServiceProvider;
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
    MarketplaceServiceProvider::class,
    SalesAgentsServiceProvider::class,
    SalesQuotationsServiceProvider::class,
    ProductSubscriptionsServiceProvider::class,
    BackInStockServiceProvider::class,
    HrServiceProvider::class,
    SupportServiceProvider::class,
    ProjectsServiceProvider::class,
    ReportingServiceProvider::class,
    ApiDocsServiceProvider::class,
];
