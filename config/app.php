<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform environment values (spec §77.1)
    |--------------------------------------------------------------------------
    */

    // Comma-separated origins of the platform admin frontend (spec §70.9).
    'platform_admin_origins' => env('PLATFORM_ADMIN_ORIGINS', ''),

    // Live payment credentials and charges are refused unless true (spec §15.9).
    'payments_live_allowed' => (bool) env('PAYMENTS_LIVE_ALLOWED', env('APP_ENV') === 'production'),

    // Seeded super-admin (spec §7.6).
    /*
    | Frontend link targets for emails (password set/reset, verification).
    | Tenant links use the tenant's primary domain with the given paths.
    */
    'frontend' => [
        'platform_admin_url' => env('PLATFORM_ADMIN_URL', 'http://localhost:3000'),
        'affiliate_portal_url' => env('AFFILIATE_PORTAL_URL', 'http://localhost:3001'),
        // The public marketing website; affiliate referral links point here (§21A.2).
        'website_url' => env('PLATFORM_WEBSITE_URL', 'http://localhost:3002'),
        'tenant_scheme' => env('TENANT_FRONTEND_SCHEME', 'https'),
        'tenant_admin_path' => env('TENANT_ADMIN_PATH', '/admin'),
    ],

    'platform_admin_email' => env('PLATFORM_ADMIN_EMAIL'),
    'platform_admin_name' => env('PLATFORM_ADMIN_NAME'),

    // Edge proxy authentication for /api/internal/* (spec §7.5).
    'edge_allowed_ips' => env('EDGE_ALLOWED_IPS', ''),
    'edge_shared_secret' => env('EDGE_SHARED_SECRET'),

];
