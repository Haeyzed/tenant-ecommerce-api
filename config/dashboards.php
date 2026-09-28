<?php

declare(strict_types=1);

use App\Modules\Accounting\Metrics\AccountingMetrics;
use App\Modules\Affiliates\Metrics\AffiliateMetricsService;
use App\Modules\Billing\Metrics\PaymentMetrics;
use App\Modules\Billing\Metrics\PlanMetrics;
use App\Modules\Billing\Metrics\RevenueMetrics;
use App\Modules\Billing\Metrics\SubscriptionMetrics;
use App\Modules\Catalog\Metrics\CatalogMetrics;
use App\Modules\Customers\Metrics\CustomerMetrics;
use App\Modules\Dashboard\Metrics\OperationsMetrics;
use App\Modules\Expenses\Metrics\ExpenseMetrics;
use App\Modules\Inventory\Metrics\InventoryMetrics;
use App\Modules\Orders\Metrics\OrderMetrics;
use App\Modules\Orders\Metrics\SalesMetrics;
use App\Modules\Payments\Metrics\PaymentMetrics as TenantPaymentMetrics;
use App\Modules\Promotions\Metrics\PromotionMetrics;
use App\Modules\Returns\Metrics\ReturnMetrics;
use App\Modules\SalesAgents\Metrics\SalesAgentMetrics;
use App\Modules\Tenancy\Metrics\TenantMetrics;

/*
|--------------------------------------------------------------------------
| Dashboard section registry (spec §22.1, §22.3, §22.6, §44)
|--------------------------------------------------------------------------
|
| Per context, each section's label, the data permission it needs (on top
| of the route's dashboard.view), the feature key it needs (tenant
| sections of optional modules), its parts and its alerts.
|
| A part is [provider class, method] or [provider class, method,
| permission]: a part with its own permission is left out for a viewer
| without it, so one section can serve viewers with different access.
| A part method returns a SectionResult; an alert method returns
| list<Alert>. The overview section also shows the critical alerts of
| every other section its viewer may see.
|
| Modules add their sections here as they are built.
|
*/

return [

    'landlord' => [
        'overview' => [
            'label' => 'Overview',
            'permission' => 'tenants.view',
            'parts' => [
                [TenantMetrics::class, 'overview'],
                [SubscriptionMetrics::class, 'overview', 'subscriptions.view'],
                [RevenueMetrics::class, 'overview', 'payment-transactions.view'],
            ],
            'alerts' => [],
        ],
        'tenants' => [
            'label' => 'Tenants',
            'permission' => 'tenants.view',
            'parts' => [
                [TenantMetrics::class, 'tenants'],
                [SubscriptionMetrics::class, 'tenantChurn', 'subscriptions.view'],
            ],
            'alerts' => [[TenantMetrics::class, 'alerts']],
        ],
        'subscriptions' => [
            'label' => 'Subscriptions',
            'permission' => 'subscriptions.view',
            'parts' => [
                [SubscriptionMetrics::class, 'subscriptions'],
                [PaymentMetrics::class, 'failedCharges', 'payment-transactions.view'],
            ],
            'alerts' => [[SubscriptionMetrics::class, 'alerts']],
        ],
        'revenue' => [
            'label' => 'Revenue',
            'permission' => 'payment-transactions.view',
            'parts' => [
                [RevenueMetrics::class, 'revenue'],
                [SubscriptionMetrics::class, 'recurring', 'subscriptions.view'],
            ],
            'alerts' => [[RevenueMetrics::class, 'alerts']],
        ],
        'plans' => [
            'label' => 'Plans',
            'permission' => 'plans.view',
            'parts' => [
                [PlanMetrics::class, 'plans'],
                [RevenueMetrics::class, 'planRevenue', 'payment-transactions.view'],
            ],
            'alerts' => [],
        ],
        'payments' => [
            'label' => 'Payments',
            'permission' => 'payment-transactions.view',
            'parts' => [
                [PaymentMetrics::class, 'payments'],
            ],
            'alerts' => [[PaymentMetrics::class, 'alerts']],
        ],
        'affiliates' => [
            'label' => 'Affiliates',
            'permission' => 'affiliates.view',
            'parts' => [
                [AffiliateMetricsService::class, 'section'],
            ],
            'alerts' => [[AffiliateMetricsService::class, 'alerts']],
        ],
        'operations' => [
            'label' => 'Operations',
            'permission' => 'database-servers.view',
            'parts' => [
                [OperationsMetrics::class, 'operations'],
            ],
            'alerts' => [[OperationsMetrics::class, 'alerts']],
        ],
    ],

    // Tenant sections (§44.3). A tenant section may also name a 'feature'
    // (an optional module key); it is listed and served only while that
    // module is readable. Optional modules add their sections as built.
    'tenant' => [
        'overview' => [
            'label' => 'Overview',
            'feature' => 'core',
            'permission' => 'orders.view',
            'parts' => [
                [SalesMetrics::class, 'overview'],
                [CustomerMetrics::class, 'overview', 'customers.view'],
                [InventoryMetrics::class, 'overview', 'inventory.view'],
            ],
            'alerts' => [],
        ],
        'sales' => [
            'label' => 'Sales',
            'feature' => 'core',
            'permission' => 'orders.view',
            'parts' => [[SalesMetrics::class, 'sales']],
            'alerts' => [],
        ],
        'orders' => [
            'label' => 'Orders',
            'feature' => 'core',
            'permission' => 'orders.view',
            'parts' => [[OrderMetrics::class, 'orders']],
            'alerts' => [[OrderMetrics::class, 'alerts']],
        ],
        'customers' => [
            'label' => 'Customers',
            'feature' => 'core',
            'permission' => 'customers.view',
            'parts' => [[CustomerMetrics::class, 'customers']],
            'alerts' => [],
        ],
        'catalogue' => [
            'label' => 'Catalogue',
            'feature' => 'core',
            'permission' => 'products.view',
            'parts' => [[CatalogMetrics::class, 'catalogue']],
            'alerts' => [],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'feature' => 'core',
            'permission' => 'inventory.view',
            'parts' => [[InventoryMetrics::class, 'inventory']],
            'alerts' => [[InventoryMetrics::class, 'alerts']],
        ],
        'promotions' => [
            'label' => 'Promotions',
            'feature' => 'core',
            'permission' => 'promotions.view',
            'parts' => [[PromotionMetrics::class, 'promotions']],
            'alerts' => [],
        ],
        'payments' => [
            'label' => 'Payments',
            'feature' => 'core',
            'permission' => 'order-payments.view',
            'parts' => [[TenantPaymentMetrics::class, 'payments']],
            'alerts' => [[TenantPaymentMetrics::class, 'alerts']],
        ],
        'returns' => [
            'label' => 'Returns',
            'feature' => 'core',
            'permission' => 'returns.view',
            'parts' => [[ReturnMetrics::class, 'returns']],
            'alerts' => [[ReturnMetrics::class, 'alerts']],
        ],
        'accounting' => [
            'label' => 'Accounting',
            'feature' => 'accounting',
            'permission' => 'accounting.reports.profit-and-loss',
            'parts' => [[AccountingMetrics::class, 'accounting']],
            'alerts' => [[AccountingMetrics::class, 'alerts']],
        ],
        'expenses' => [
            'label' => 'Expenses',
            'feature' => 'expenses',
            'permission' => 'expenses.view',
            'parts' => [[ExpenseMetrics::class, 'expenses']],
            'alerts' => [],
        ],
        'sales_agents' => [
            'label' => 'Sales agents',
            'feature' => 'sales_agents',
            'permission' => 'sales-agents.view',
            'parts' => [[SalesAgentMetrics::class, 'salesAgents']],
            'alerts' => [],
        ],
    ],

];
