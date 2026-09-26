<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantDatabasePreparer;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Tenant fixtures backed by real MySQL databases (spec §76.5).
 *
 * Each fixture key ("a", "b") owns one physical database, created, migrated
 * and seeded once per schema signature and reused by every test. Test data
 * written inside it is rolled back after each test.
 */
trait InteractsWithTenants
{
    /** @var array<string, bool> */
    private static array $preparedTenantDatabases = [];

    /**
     * While false, tenant connections are not wrapped in a test transaction
     * (used while a fixture database is migrated and seeded).
     */
    protected static bool $wrapTenantTransactions = true;

    protected function createTenant(string $key = 'a', array $attributes = []): Tenant
    {
        $databaseName = config('tenancy.database.prefix').$key;

        $tenant = new Tenant(array_merge([
            'id' => 'test-tenant-'.$key,
            'name' => 'Tenant '.strtoupper($key),
            'slug' => 'tenant-'.$key,
            'owner_name' => 'Owner '.strtoupper($key),
            'email' => "owner-{$key}@example.test",
            'status' => TenantStatus::Active,
            'timezone' => 'UTC',
            'country_id' => 1,
            'default_currency' => 'USD',
            'provisioned_at' => now(),
        ], $attributes));
        $tenant->setInternal('db_name', $databaseName);
        $tenant->save();

        $tenant->domains()->create([
            'domain' => $this->tenantHost($key),
            'type' => 'subdomain',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->prepareTenantDatabase($tenant, $databaseName);

        return $tenant->refresh();
    }

    /**
     * A landlord tenant row without a database, for tests that only need
     * another tenant to exist (e.g. cross-tenant access checks) and must
     * not disturb the current tenant's test transaction.
     */
    protected function createTenantRow(string $key): string
    {
        $tenant = new Tenant([
            'id' => 'test-tenant-'.$key,
            'name' => 'Tenant '.strtoupper($key),
            'slug' => 'tenant-'.$key,
            'owner_name' => 'Owner '.strtoupper($key),
            'email' => "owner-{$key}@example.test",
            'status' => TenantStatus::Active,
            'timezone' => 'UTC',
            'country_id' => 1,
            'default_currency' => 'USD',
        ]);
        $tenant->save();

        return (string) $tenant->id;
    }

    protected function tenantHost(string $key = 'a'): string
    {
        return 'tenant-'.$key.'.'.config('tenancy.root_domain');
    }

    protected function landlordHost(): string
    {
        return (string) config('tenancy.root_domain');
    }

    /**
     * Run a JSON request against a tenant domain.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function tenantJson(string $method, string $uri, array $data = [], array $headers = [], string $key = 'a'): TestResponse
    {
        // Each request resolves its actor afresh, as a new process would.
        $this->app['auth']->forgetGuards();

        return $this->json($method, 'http://'.$this->tenantHost($key).$uri, $data, $headers);
    }

    /**
     * Run a JSON request against the landlord domain. Tenancy is ended first,
     * exactly as a fresh request process would start.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function landlordJson(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->app['auth']->forgetGuards();

        return $this->json($method, 'http://'.$this->landlordHost().$uri, $data, $headers);
    }

    private function prepareTenantDatabase(Tenant $tenant, string $databaseName): void
    {
        $preparer = $this->app->make(TenantDatabasePreparer::class);
        $signature = $preparer->schemaSignature();
        $marker = storage_path('framework/testing/'.$databaseName.'.signature');

        if (isset(self::$preparedTenantDatabases[$databaseName])) {
            return;
        }

        $exists = DB::connection('tenant_template')->selectOne(
            'select schema_name from information_schema.schemata where schema_name = ?',
            [$databaseName],
        ) !== null;

        if (! $exists || ! is_file($marker) || file_get_contents($marker) !== $signature) {
            DB::connection('tenant_template')->statement("drop database if exists `{$databaseName}`");
            DB::connection('tenant_template')->statement("create database `{$databaseName}` character set utf8mb4 collate utf8mb4_unicode_ci");

            self::$wrapTenantTransactions = false;

            try {
                $preparer->prepare($tenant);
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }

                self::$wrapTenantTransactions = true;
            }

            if (! is_dir(dirname($marker))) {
                mkdir(dirname($marker), 0755, true);
            }

            file_put_contents($marker, $signature);
        }

        self::$preparedTenantDatabases[$databaseName] = true;
    }
}
