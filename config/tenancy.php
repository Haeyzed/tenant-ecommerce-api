<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Tenancy\PrefixedCacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\UUIDGenerator;

/*
|--------------------------------------------------------------------------
| Tenancy (spec §6)
|--------------------------------------------------------------------------
*/

$rootDomain = (string) env('PLATFORM_ROOT_DOMAIN', 'tenant-ecommerce-api.test');

$centralDomains = array_values(array_unique(array_filter(array_map(
    'trim',
    array_merge(explode(',', (string) env('CENTRAL_DOMAINS', 'localhost,127.0.0.1')), [$rootDomain]),
))));

$redisPrefixed = array_values(array_filter(explode(',', (string) env('TENANCY_REDIS_PREFIXED_CONNECTIONS', ''))));

return [

    'tenant_model' => Tenant::class,

    'id_generator' => UUIDGenerator::class,

    'domain_model' => Domain::class,

    /*
     * Landlord domains (spec §6.2). The platform root domain is always one.
     */
    'central_domains' => $centralDomains,

    /*
     * Tenant subdomains are "{slug}.{root_domain}" (spec §6.2).
     */
    'root_domain' => $rootDomain,

    'bootstrappers' => array_values(array_filter([
        DatabaseTenancyBootstrapper::class,
        PrefixedCacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        $redisPrefixed === [] ? null : RedisTenancyBootstrapper::class,
    ])),

    'database' => [
        'central_connection' => 'landlord',

        'template_tenant_connection' => 'tenant_template',

        'prefix' => env('TENANCY_DB_PREFIX', 'tea_tenant_'),
        'suffix' => '',

        'managers' => [
            'mysql' => MySQLDatabaseManager::class,
        ],
    ],

    'cache' => [
        'tag_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenants/',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'tenant_',
        'prefixed_connections' => $redisPrefixed,
    ],

    'features' => [],

    /*
     * The package's tenant asset route is not used: files are served by
     * temporary signed URLs (spec §19.3, §75 rule 20).
     */
    'routes' => false,

    /*
     * Tenant migrations run by path only (spec §8.1, §73.5). CMS tables are
     * shared with the landlord database (spec §24.1).
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [
            database_path('migrations/tenant'),
            database_path('migrations/shared/cms'),
        ],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\Tenant\\TenantDatabaseSeeder',
        '--force' => true,
    ],
];
