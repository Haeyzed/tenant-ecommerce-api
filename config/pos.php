<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Point of sale (spec §51)
|--------------------------------------------------------------------------
|
| Card-terminal endpoints. Credentials are entered per register (encrypted
| in the tenant database), never here. Terminals have no test mode in v1:
| card_terminal payments are refused while a store is in test mode.
|
*/

return [
    'terminals' => [
        'moniepoint' => [
            'base_url' => env('POS_MONIEPOINT_BASE_URL', 'https://channel.moniepoint.com'),
        ],
        'opay' => [
            'base_url' => env('POS_OPAY_BASE_URL', 'https://liveapi.opaycheckout.com'),
            'country' => env('POS_OPAY_COUNTRY', 'NG'),
        ],
        'stripe_terminal' => [
            'base_url' => 'https://api.stripe.com',
        ],
    ],

    // An offline sale synced later may carry its own sale time (§51.6), up to this age.
    'offline_sale_max_age_hours' => (int) env('POS_OFFLINE_SALE_MAX_AGE_HOURS', 72),

    // Without cash sessions, a sale can be voided for this long (A-34 applies to sessions).
    'void_window_hours' => 24,
];
