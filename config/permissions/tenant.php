<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Canonical tenant permissions (spec §12.3, §12.4, §12.6)
|--------------------------------------------------------------------------
|
| "permissions" is generated from the tenant.admin routes by
| `php artisan permissions:generate` and checked by an architecture test.
| "roles" are the default roles' starter sets as permission-name patterns
| ("*" matches any characters). "version" is bumped by every release that
| changes a synced default, and is recorded on tenants.permissions_version.
|
*/

return [

    'version' => '2026.10.1',

    'guard' => 'staff',

    // The permission list itself: config('permissions.generated.tenant').

    /*
     * Protected roles: owner holds every permission; admin every permission
     * except the excluded patterns. The sync keeps both current.
     */
    'protected' => [
        'owner' => ['include' => ['*'], 'exclude' => []],
        'admin' => [
            'include' => ['*'],
            'exclude' => ['billing.*', 'modules.*', 'users.transfer-ownership', 'legal-acceptances.*', 'payment-settings.mode'],
        ],
    ],

    /*
     * Unprotected starter roles: created once with these patterns, then owned
     * by the tenant (the sync never changes them again).
     */
    'roles' => [
        'manager' => [
            'products.*', 'categories.*', 'brands.*', 'product-options.*', 'units.*', 'tags.*', 'product-questions.*',
            'product-answers.*', 'inventory.*', 'warehouses.*', 'stock-transfers.*', 'stock-adjustments.*',
            'orders.*', 'order-payments.view', 'shipments.*', 'delivery-assignments.*', 'drivers.*', 'returns.*',
            'return-reasons.*', 'customers.*', 'customer-groups.*', 'promotions.*', 'coupons.*', 'flash-sales.*',
            'reviews.*', 'cms.*', 'reports.*', 'dashboard.view', 'exports.*', 'lookups.*',
        ],
        'staff' => [
            'orders.view', 'orders.status', 'orders.invoice', 'orders.packing-slip', 'orders.shipments.*',
            'shipments.view', 'customers.view', 'customers.create', 'customers.update', 'products.view',
            'categories.view', 'brands.view', 'inventory.view', 'dashboard.view', 'lookups.*',
        ],
        'accountant' => [
            'accounting.*', 'billers.*', 'expense-categories.*', 'expenses.*', 'income-categories.*', 'income.*',
            'order-payments.view', 'orders.payments.view', 'orders.balance.view', 'returns.view', 'reports.*',
            'exports.*', 'dashboard.view', 'lookups.*',
        ],
        'sales' => [
            'customers.*', 'orders.view', 'orders.duplicate', 'sales-quotation-requests.*', 'sales-quotations.*',
            'pos.*', 'gift-cards.view', 'gift-cards.create', 'products.view', 'dashboard.view', 'lookups.*',
        ],
        'warehouse' => [
            'inventory.*', 'warehouses.view', 'warehouses.inventory.view', 'stock-transfers.*',
            'purchase-orders.view', 'purchase-orders.receive', 'shipments.*', 'orders.view', 'orders.packing-slip',
            'orders.items.warehouse', 'products.view', 'products.inventory.view', 'dashboard.view', 'lookups.*',
        ],
        'hr' => [
            'hr.*', 'dashboard.view', 'lookups.*',
        ],
    ],

];
