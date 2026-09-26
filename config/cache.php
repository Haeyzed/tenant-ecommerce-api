<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Cache (spec §74)
|--------------------------------------------------------------------------
|
| The default store is re-prefixed per tenant by
| App\Shared\Tenancy\PrefixedCacheTenancyBootstrapper, so tenant code can use
| Cache::... directly. Landlord services MUST use Cache::store('landlord'),
| which is never re-prefixed, so a value written from a tenant context is
| visible to, and bustable from, the landlord context.
|
| Database stores always use the landlord connection: inside a tenant
| context the default connection is the tenant database.
|
*/

$landlordDriver = env('LANDLORD_CACHE_STORE', env('CACHE_STORE', 'database'));

return [

    'default' => env('CACHE_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION', 'landlord'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION', 'landlord'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'landlord' => match ($landlordDriver) {
            'redis' => [
                'driver' => 'redis',
                'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
                'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
                'prefix' => Str::slug((string) env('APP_NAME', 'laravel')).'-landlord-',
            ],
            'array' => [
                'driver' => 'array',
                'serialize' => false,
            ],
            default => [
                'driver' => 'database',
                'connection' => 'landlord',
                'table' => env('DB_CACHE_TABLE', 'cache'),
                'lock_connection' => 'landlord',
                'prefix' => Str::slug((string) env('APP_NAME', 'laravel')).'-landlord-',
            ],
        },

    ],

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    'serializable_classes' => false,

];
