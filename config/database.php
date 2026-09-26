<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
|--------------------------------------------------------------------------
| Database connections (spec §6.1, §6.6)
|--------------------------------------------------------------------------
|
| "landlord" is the default connection in the landlord context. The "tenant"
| connection is never defined here: stancl/tenancy builds it per request or
| job from "tenant_template" plus the tenant's database server row
| (App\Modules\Tenancy\Support\TenantDatabaseConfig).
|
*/

// A variable that is present but empty (e.g. "TENANT_DB_HOST=") falls back
// too: env() returns '' for it, which must not blank out the connection.
$fallback = static fn (string $key, string $default): mixed => (($value = env($key)) !== null && $value !== '') ? $value : $default;

$mysql = static fn (string $prefix, string $database): array => [
    'driver' => 'mysql',
    'url' => env($prefix.'URL') ?: null,
    'host' => $fallback($prefix.'HOST', (string) $fallback('DB_HOST', '127.0.0.1')),
    'port' => $fallback($prefix.'PORT', (string) $fallback('DB_PORT', '3306')),
    'database' => $database,
    'username' => $fallback($prefix.'USERNAME', (string) $fallback('DB_USERNAME', 'root')),
    'password' => $fallback($prefix.'PASSWORD', (string) env('DB_PASSWORD', '')),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => 'InnoDB',
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
];

return [

    'default' => env('DB_CONNECTION', 'landlord'),

    'connections' => [

        'landlord' => $mysql('DB_', (string) env('DB_DATABASE', 'tea_landlord')),

        /*
         * Template for every tenant database connection. Host, port and
         * credentials are replaced by the tenant's database server row.
         */
        'tenant_template' => $mysql('TENANT_DB_', ''),

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_SQLITE_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
        ],

    ],

];
