<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Canonical landlord permissions (spec §12.2, §12.4)
|--------------------------------------------------------------------------
|
| "permissions" is generated from the landlord.admin routes by
| `php artisan permissions:generate`. "roles" are the seeded platform roles
| as permission-name patterns. super-admin always holds every permission.
|
*/

return [

    'guard' => 'platform',

    // The permission list itself: config('permissions.generated.landlord').

    'roles' => [
        'super-admin' => ['*'],
        'support-staff' => [
            'tenants.view', 'tenant-registrations.view', 'platform-support.*', 'cms.contact-submissions.*',
            'module-notices.*', 'dashboard.view', 'lookups.*',
        ],
        'billing-admin' => [
            'plans.*', 'tenants.view', 'tenants.modules.view', 'tenants.features.*', 'tenants.limit-overrides.*',
            'subscriptions.*', 'payment-transactions.*', 'platform-coupons.*', 'affiliate-payouts.*',
            'affiliate-commissions.view', 'affiliates.view', 'dashboard.view', 'lookups.*',
        ],
        'affiliate-manager' => [
            'affiliates.*', 'affiliate-referrals.*', 'affiliate-commissions.*', 'affiliate-payouts.view',
            'dashboard.view', 'lookups.*',
        ],
        'content-editor' => [
            'cms.*', 'legal-documents.view', 'legal-documents.create', 'legal-documents.update',
            'legal-documents.acceptances.view', 'lookups.*',
        ],
    ],

    /*
     * Held only by super-admin (spec §12.2, §75 rule 29); stripped from every
     * other seeded role even when a pattern would match.
     */
    'super_admin_only' => [
        'payment-gateways.*', 'platform-settings.update', 'platform-users.*', 'legal-documents.publish',
    ],

];
