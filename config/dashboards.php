<?php

declare(strict_types=1);

use App\Modules\Affiliates\Metrics\AffiliateMetricsService;
use App\Modules\Billing\Metrics\PaymentMetrics;
use App\Modules\Billing\Metrics\PlanMetrics;
use App\Modules\Billing\Metrics\RevenueMetrics;
use App\Modules\Billing\Metrics\SubscriptionMetrics;
use App\Modules\Dashboard\Metrics\OperationsMetrics;
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

    // Tenant sections are registered with the tenant dashboard (§44).
    'tenant' => [],

];
