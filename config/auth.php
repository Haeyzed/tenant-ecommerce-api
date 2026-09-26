<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Customers\Models\Customer;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Users\Models\User;

/*
|--------------------------------------------------------------------------
| Authentication (spec §10)
|--------------------------------------------------------------------------
|
| Six actor types, one guard each. Every guard uses Sanctum bearer tokens;
| its provider only accepts that actor's model, and auth.as:{actor} also
| requires the token's single ability to equal the actor (spec §10.2).
|
*/

return [

    'defaults' => [
        'guard' => 'platform',
        'passwords' => 'platform_users',
    ],

    'guards' => [
        'platform' => ['driver' => 'sanctum', 'provider' => 'platform_users'],
        'affiliate' => ['driver' => 'sanctum', 'provider' => 'affiliates'],
        'staff' => ['driver' => 'sanctum', 'provider' => 'users'],
        'customer' => ['driver' => 'sanctum', 'provider' => 'customers'],
        'seller' => ['driver' => 'sanctum', 'provider' => 'sellers'],
        'driver' => ['driver' => 'sanctum', 'provider' => 'drivers'],
    ],

    'providers' => [
        'platform_users' => ['driver' => 'eloquent', 'model' => PlatformUser::class],
        'affiliates' => ['driver' => 'eloquent', 'model' => Affiliate::class],
        'users' => ['driver' => 'eloquent', 'model' => User::class],
        'customers' => ['driver' => 'eloquent', 'model' => Customer::class],
        'sellers' => ['driver' => 'eloquent', 'model' => Seller::class],
        'drivers' => ['driver' => 'eloquent', 'model' => Driver::class],
    ],

    /*
     * Password brokers (spec §10.4). Tenant broker tables live in each
     * tenant database; the landlord ones in the landlord database. The
     * connection is left to the context's default connection.
     */
    'passwords' => [
        'platform_users' => [
            'provider' => 'platform_users',
            'table' => 'platform_user_password_reset_tokens',
            'connection' => 'landlord',
            'expire' => 60,
            'throttle' => 60,
        ],
        'affiliates' => [
            'provider' => 'affiliates',
            'table' => 'affiliate_password_reset_tokens',
            'connection' => 'landlord',
            'expire' => 60,
            'throttle' => 60,
        ],
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'customers' => [
            'provider' => 'customers',
            'table' => 'customer_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'sellers' => [
            'provider' => 'sellers',
            'table' => 'seller_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
